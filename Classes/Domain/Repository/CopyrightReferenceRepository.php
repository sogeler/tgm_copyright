<?php
namespace TGM\TgmCopyright\Domain\Repository;


/***************************************************************
 *
 *  Copyright notice
 *
 *  (c) 2016 Paul Beck <hi@toll-paul.de>, Teamgeist Medien GbR
 *
 *  All rights reserved
 *
 *  This script is part of the TYPO3 project. The TYPO3 project is
 *  free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 3 of the License, or
 *  (at your option) any later version.
 *
 *  The GNU General Public License can be found at
 *  http://www.gnu.org/copyleft/gpl.html.
 *
 *  This script is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  This copyright notice MUST APPEAR in all copies of the script!
 ***************************************************************/

use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * The repository for Copyrights
 */
class CopyrightReferenceRepository extends \TYPO3\CMS\Extbase\Persistence\Repository
{

    /**
     * @param array $settings
     * @return array|\TYPO3\CMS\Extbase\Persistence\QueryResultInterface
     */
    public function findByRootline($settings) {

        // First main statement, exclude by all possible exclusion reasons
        $preQuery = $this->createQuery();

        $context = GeneralUtility::makeInstance(\TYPO3\CMS\Core\Context\Context::class);
        $sysLanguage = (int) $context->getPropertyFromAspect('language', 'id');

        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('sys_file_reference');

        $queryBuilder
            ->selectLiteral('ref.uid', 'ref.tablenames', 'ref.uid_foreign', 'ref.copyright', 'metadata.copyright AS mcp', 'ref.pid')
            ->from('sys_file_reference', 'ref')
            ->leftJoin(
                'ref',
                'sys_file',
                'file',
                $queryBuilder->expr()->eq('file.uid', 'ref.uid_local')
            )
            ->leftJoin(
                'file',
                'sys_file_metadata',
                'metadata',
                $queryBuilder->expr()->eq('metadata.file', 'file.uid')
            )
            ->leftJoin(
                'ref',
                'pages',
                'p',
                $queryBuilder->expr()->eq('ref.pid', 'p.uid')
            );

        $constraints = [
            $queryBuilder->expr()->eq('ref.sys_language_uid', $sysLanguage),
            $queryBuilder->expr()->eq('missing', 0),
            $queryBuilder->expr()->isNotNull('file.uid'),
            $queryBuilder->expr()->in('file.type', [2, 5]),
            $queryBuilder->expr()->orX(
                $queryBuilder->expr()->isNotNull('ref.copyright'),
                $queryBuilder->expr()->isNotNull('metadata.copyright'),
            ),
        ];

        $queryBuilder
            ->where(
                ...$constraints
            );

        $this->getStatementDefaults($settings['rootlines'], $queryBuilder, (bool) $settings['onlyCurrentPage']);

        $preResults = $queryBuilder->execute();

        if((int)$settings['displayDuplicateImages'] === 0) {
            $queryBuilder->groupBy('file.uid');
        }

        $typo3Version = new \TYPO3\CMS\Core\Information\Typo3Version();
        if(version_compare($typo3Version->getVersion(),'11', '<')) {
            $preResults = $preResults->fetchAll();
        } else {
            $preResults = $preResults->fetchAllAssociative();
        }

        // Now check if the foreign record has a endtime field which is expired
        $finalRecords = $this->filterPreResultsReturnUids($preResults);

        // Final select
        if(false === empty($finalRecords)) {
            $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
                ->getQueryBuilderForTable('sys_file_reference');
            $records = $queryBuilder
                ->select('*')
                ->from('sys_file_reference')
                ->where(
                    $queryBuilder->expr()->in('uid', $finalRecords)
                )
                ->execute();

            if(version_compare($typo3Version->getVersion(),'11', '<')) {
                $records = $records->fetchAll();
                $objectManager = GeneralUtility::makeInstance(\TYPO3\CMS\Extbase\Object\ObjectManager::class);
                $dataMapper = $objectManager->get(\TYPO3\CMS\Extbase\Persistence\Generic\Mapper\DataMapper::class);
            } else {
                $records = $records->fetchAllAssociative();
                $dataMapper = GeneralUtility::makeInstance(\TYPO3\CMS\Extbase\Persistence\Generic\Mapper\DataMapper::class);
            }

            return $dataMapper->map(\TGM\TgmCopyright\Domain\Model\CopyrightReference::class, $records);
        }

        return [];
    }

    /**
     * @param string $rootlines
     * @return array
     */
    public function findForSitemap($rootlines) {

        $typo3Version = new \TYPO3\CMS\Core\Information\Typo3Version();

        $context = GeneralUtility::makeInstance(\TYPO3\CMS\Core\Context\Context::class);
        $sysLanguage = (int) $context->getPropertyFromAspect('language', 'id');

        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('sys_file_reference');

        $constraints = [
            $queryBuilder->expr()->eq('ref.sys_language_uid', $sysLanguage),
            $queryBuilder->expr()->eq('missing', 0),
            $queryBuilder->expr()->isNotNull('file.uid'),
            $queryBuilder->expr()->in('file.type', [2, 5]),
        ];

        if ('' !== $rootlines) {
            $constraints[] = $queryBuilder->expr()->in('ref.pid', $this->extendPidListByChildren($rootlines));
        }

        $preResults = $queryBuilder
            ->selectLiteral('ref.uid', 'ref.tablenames', 'ref.uid_foreign')
            ->from('sys_file_reference', 'ref')
            ->leftJoin(
                'ref',
                'sys_file',
                'file',
                $queryBuilder->expr()->eq('file.uid', 'ref.uid_local')
            )
            ->join(
                'ref',
                'pages',
                'p',
                $queryBuilder->expr()->eq('ref.pid', 'p.uid')
            )
            ->where(
                ...$constraints
            )
            ->execute();

        if(version_compare($typo3Version->getVersion(),'11', '<')) {
            $preResults = $preResults->fetchAll();
        } else {
            $preResults = $preResults->fetchAllAssociative();
        }

        // Now check if the foreign record has a endtime field which is expired
        $finalRecords = $this->filterPreResultsReturnUids($preResults);

        // Final select
        if(false === empty($finalRecords)) {

            $queryBuilder->resetQueryParts();
            $records = $queryBuilder
                ->select('*')
                ->from('sys_file_reference')
                ->where(
                    $queryBuilder->expr()->in('uid', $finalRecords)
                )
                ->execute();

            if(version_compare($typo3Version->getVersion(),'11', '<')) {
                $records = $records->fetchAll();
                $objectManager = GeneralUtility::makeInstance(\TYPO3\CMS\Extbase\Object\ObjectManager::class);
                $dataMapper = $objectManager->get(\TYPO3\CMS\Extbase\Persistence\Generic\Mapper\DataMapper::class);
            } else {
                $records = $records->fetchAllAssociative();
                $dataMapper = GeneralUtility::makeInstance(\TYPO3\CMS\Extbase\Persistence\Generic\Mapper\DataMapper::class);
            }

            return $dataMapper->map(\TGM\TgmCopyright\Domain\Model\CopyrightReference::class, $records);
        }

        return [];
    }

    /**
     * @param string $rootlines
     * @param \TYPO3\CMS\Core\Database\Query\QueryBuilder $qb
     * @param bool $onlyCurrentPage
     */
    public function getStatementDefaults($rootlines, $qb, $onlyCurrentPage = false) {

        $rootlines = (string) $rootlines;

        if($onlyCurrentPage === true) {
            $qb->andWhere(
                $qb->expr()->eq('ref.pid', $GLOBALS['TSFE']->id)
            );
        } else if($rootlines !== '') {
            $qb->andWhere(
                $qb->expr()->in('ref.pid', $this->extendPidListByChildren($rootlines))
            );
        }
    }

    /**
     * This function will remove results which related table records are not hidden by endtime
     * @param array $preResults raw sql results to filter
     * @return array
     */
    public function filterPreResultsReturnUids($preResults) {

        $finalRecords = [];

        foreach($preResults as $preResult) {
            if((isset($preResult['tablenames']) && isset($preResult['uid_foreign']))
                && (strlen($preResult['tablenames']) > 0 && strlen($preResult['uid_foreign']) > 0))
                {

                /*
                 * Thanks to the QueryBuilder we don't have to check end- and starttime, deleted, hidden manually before because of the default RestrictionContainers
                 * Just check if there is a result or not
                 */
                $queryBuilder = GeneralUtility::makeInstance(\TYPO3\CMS\Core\Database\ConnectionPool::class)->getQueryBuilderForTable($preResult['tablenames']);
                $foreignRecord = $queryBuilder
                    ->select('uid')
                    ->from($preResult['tablenames'])
                    ->where(
                        $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($preResult['uid_foreign']))
                    )
                    ->execute();

                $typo3Version = new \TYPO3\CMS\Core\Information\Typo3Version();

                if(version_compare($typo3Version->getVersion(),'11', '<')) {
                    $foreignRecord = $foreignRecord->fetch();
                } else {
                    $foreignRecord = $foreignRecord->fetchAssociative();
                }

                if($foreignRecord === false || $foreignRecord === false) {
                    // Exclude if nothing found
                    continue;
                }

                // Add the record to the final select if the foreign record is not expired or does not have a field endtime
                $finalRecords[] = $preResult['uid'];
            }
        }

        return $finalRecords;
    }

    /**
     * Find all ids from given ids and level by Georg Ringer
     * @param string $pidList comma separated list of ids
     * @param int $recursive recursive levels
     * @return string comma separated list of ids
     */
    private function extendPidListByChildren($pidList = '')
    {
        $recursive = 1000;
        $queryGenerator = GeneralUtility::makeInstance(\TYPO3\CMS\Core\Database\QueryGenerator::class);
        $recursiveStoragePids = $pidList;
        $storagePids = GeneralUtility::intExplode(',', $pidList);
        foreach ($storagePids as $startPid) {
            $pids = $queryGenerator->getTreeList($startPid, $recursive, 0, 1);
            if (strlen($pids) > 0) {
                $recursiveStoragePids .= ',' . $pids;
            }
        }
        return $recursiveStoragePids;
    }
}
