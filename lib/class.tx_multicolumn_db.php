<?php

/***************************************************************
 *  Copyright notice
 *
 *  (c) 2010 snowflake productions GmbH
 *  All rights reserved
 *
 *  This script is part of the TYPO3 project. The TYPO3 project is
 *  free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 2 of the License, or
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

use TYPO3\CMS\Backend\View\PageLayoutView;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\BackendWorkspaceRestriction;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class tx_multicolumn_db
{
    /**
     * This function is deprecated. Do not use.
     *
     * @return bool
     * @deprecated
     */
    public static function isBackend()
    {
        GeneralUtility::logDeprecatedFunction();

        return TYPO3_MODE == 'BE';
    }

    /**
     * Is the user in a workspace ?
     *
     * @return    bool        ture if the user is an a workspace
     */
    public static function isWorkspaceActive()
    {
        return !empty($GLOBALS['BE_USER']->workspace) || !empty($GLOBALS['TSFE']->sys_page->versioningPreview);
    }

    /**
     * Get content elements from tt_content table
     *
     * @param int $colPos
     * @param int $pid
     * @param int $mulitColumnParentId
     * @param int $sysLanguageUid
     * @param bool $showHidden
     * @param string $additionalWhere
     * @param PageLayoutView $cmsLayout
     *
     * @return array Array with database fields
     */
    public static function getContentElementsFromContainer($colPos, $pid, $mulitColumnParentId, $sysLanguageUid = 0, $showHidden = false, $additionalWhere = null, $cmsLayout = null)
    {
        $isWorkspace = self::isWorkspaceActive();

        $selectFields = '*';
        $fromTable = 'tt_content';

        if (version_compare(TYPO3_version, '8', '>=') && $cmsLayout) {
            $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
                ->getQueryBuilderForTable('tt_content');
            if ($isWorkspace) {
                $queryBuilder->getRestrictions()->add(BackendWorkspaceRestriction::class);
            }
            $queryBuilder->select($selectFields)
                ->from($fromTable)
                ->where(
                    $queryBuilder->expr()->eq('tx_multicolumn_parentid', (int)$mulitColumnParentId),
                    $queryBuilder->expr()->eq('sys_language_uid', (int)$sysLanguageUid)
                )
                ->orderBy('sorting');
            if (!empty($additionalWhere)) {
                $queryBuilder->andWhere($additionalWhere);
            }
            if ($colPos) {
                $queryBuilder->andWhere($queryBuilder->expr()->eq('colPos', (int)$colPos));
            }
            if ($pid && !$isWorkspace) {
                $queryBuilder->andWhere($queryBuilder->expr()->eq('pid', (int)$pid));
            }
            $res = $queryBuilder->execute();
            $output = $cmsLayout->getResult($res, 'tt_content', 1);
        } else {
            $whereClause = ($additionalWhere ? $additionalWhere : '1=1');
            if ($colPos) {
                $whereClause .= ' AND colPos=' . intval($colPos);
            }
            if ($pid && !$isWorkspace) {
                $whereClause .= ' AND pid=' . intval($pid);
            }

            $whereClause .= ' AND tx_multicolumn_parentid=' . intval($mulitColumnParentId);
            $whereClause .= ' AND sys_language_uid=' . intval($sysLanguageUid);

            // enable fields
            $whereClause .= self::enableFields($fromTable, $showHidden);
            if ($isWorkspace) {
                $whereClause = self::getWorkspaceClause($whereClause);
            }

            $orderBy = 'sorting ASC';

            if ($cmsLayout) {
                // use cms layout object for correct icons
                $res = $GLOBALS['TYPO3_DB']->exec_SELECTquery($selectFields, $fromTable, $whereClause, '', $orderBy);
                $output = $cmsLayout->getResult($res, 'tt_content', 1);
                $GLOBALS['TYPO3_DB']->sql_free_result($res);
            } else {
                $output = $GLOBALS['TYPO3_DB']->exec_SELECTgetRows($selectFields, $fromTable, $whereClause, '', $orderBy);
            }
        }

        return $output;
    }

    /**
     * Add additional workspace clause if needed
     *
     * @param string $whereClause
     *
     * @return string
     */
    public static function getWorkspaceClause($whereClause)
    {
        $table = 'tt_content';

        if (!empty($GLOBALS['BE_USER']->workspace)) {
            $workspaceId = intval($GLOBALS['BE_USER']->workspace);
            $workspaceClause = ' AND (' . $table . '.t3ver_wsid=' . $workspaceId . ' OR ' . $table . '.t3ver_wsid=0)';

            if (strstr($whereClause, ' AND tt_content.pid > 0')) {
                $whereClause = str_replace(' AND tt_content.pid > 0', $workspaceClause, $whereClause);
            } else {
                $whereClause = str_replace(' AND tt_content.deleted=0', ' AND tt_content.deleted=0' . $workspaceClause, $whereClause);
            }
        }

        return $whereClause;
    }

    /**
     * Get number of content elements inside a multicolumn container
     *
     * @param int $mulitColumnId
     *
     * @return int
     */
    public static function getNumberOfContentElementsFromContainer($mulitColumnId)
    {
        $selectFields = 'COUNT(*) AS counter';
        $fromTable = 'tt_content';
        $whereClause = 'tt_content.tx_multicolumn_parentId=' . intval($mulitColumnId) . self::enableFields($fromTable);

        list($row) = $GLOBALS['TYPO3_DB']->exec_SELECTgetRows($selectFields, $fromTable, $whereClause);

        return is_array($row) ? $row['counter'] : 0;
    }

    /**
     * Get number of columns in the container
     *
     * @param int $mulitColumnId
     * @param array $row
     * @return int
     */
    public static function getNumberOfColumnsFromContainer($mulitColumnId, array $row = null)
    {
        $result = 0;
        if ($row === null || !isset($row['pi_flexform'])) {
            $row = self::getContentElement($mulitColumnId);
        }
        if (!empty($row['pi_flexform'])) {
            $flexObj = GeneralUtility::makeInstance('tx_multicolumn_flexform', $row['pi_flexform']);
            $layoutConfiguration = tx_multicolumn_div::getLayoutConfiguration($row['pid'], $flexObj);
            $result = (int)$layoutConfiguration['columns'];
        }

        return $result;
    }

    /**
     * Get a single content element
     *
     * @param int $uid
     * @param string $selectFields
     * @param string $additionalWhere
     * @param bool $useDeleteClause
     *
     * @return array Element fields
     */
    public static function getContentElement($uid, $selectFields = '*', $additionalWhere = null, $useDeleteClause = true)
    {
        if (TYPO3_MODE == 'BE') {
            return \TYPO3\CMS\Backend\Utility\BackendUtility::getRecordWSOL('tt_content', $uid, $selectFields, $additionalWhere, $useDeleteClause);
        }

        $fromTable = 'tt_content';
        $whereClause = ' uid=' . intval($uid);
        if ($additionalWhere) {
            $whereClause .= ' ' . $additionalWhere;
        }

        list($row) = $GLOBALS['TYPO3_DB']->exec_SELECTgetRows($selectFields, $fromTable, $whereClause);

        return $row;
    }

    /**
     * Get multicolumn content elements from page uid
     *
     * @param int $pid
     * @param int $sysLanguageUid
     * @param int $selectFields
     *
     * @return array
     */
    public static function getContainersFromPid($pid, $sysLanguageUid = 0, $selectFields = 'uid,header')
    {
        $fromTable = 'tt_content';

        $whereClause = ' pid=' . intval($pid) . ' AND CType=\'multicolumn\'';
        $whereClause .= ' AND sys_language_uid = ' . intval($sysLanguageUid);
        $whereClause .= self::enableFields('tt_content');
        $orderBy = 'sorting';

        return $GLOBALS['TYPO3_DB']->exec_SELECTgetRows($selectFields, $fromTable, $whereClause, '', $orderBy);
    }

    /**
     * Get multicolumn content element from uid
     *
     * @param int $uid
     * @param int $selectFields
     * @param bool $enableFields
     *
     * @return array|null
     */
    public static function getContainerFromUid($uid, $selectFields = 'uid,header', $enableFields = false)
    {
        $fromTable = 'tt_content';

        $whereClause = 'uid=' . intval($uid) . ' AND CType=\'multicolumn\'';
        if ($enableFields) {
            $whereClause .= self::enableFields('tt_content');
        }

        list($container) = $GLOBALS['TYPO3_DB']->exec_SELECTgetRows($selectFields, $fromTable, $whereClause);

        return $container;
    }

    /**
     * Checks if content element has an parent multicolumn content element
     *
     * @param int $uid
     *
     * @return bool
     */
    public static function contentElementHasAMulticolumnParentContainer($uid)
    {
        $fromTable = 'tt_content';
        $selectFields = 'COUNT(*) AS counter';
        $whereClause = 'uid=' . intval($uid) . ' AND tx_multicolumn_parentid<>0';
        $whereClause .= self::enableFields('tt_content');

        list($row) = $GLOBALS['TYPO3_DB']->exec_SELECTgetRows($selectFields, $fromTable, $whereClause);

        return $row['counter'] > 0;
    }

    /**
     * Updateds a content element
     *
     * @param int $uid
     * @param array $fieldValues
     *
     * @return void
     */
    public static function updateContentElement($uid, array $fieldValues)
    {
        $table = 'tt_content';
        $where = 'tt_content.uid=' . intval($uid);

        $GLOBALS['TYPO3_DB']->exec_UPDATEquery($table, $where, $fieldValues);
    }

    /**
     * Obtains children content elements for the multicolumn container
     *
     * @param int $containerUid
     * @param string $showHidden
     *
     * @return array
     */
    public static function getContainerChildren($containerUid, $showHidden = true)
    {
        $fromTable = 'tt_content';
        $selectFields = 'uid,pid,sys_language_uid,CType';
        $whereClause = 'tx_multicolumn_parentid=' . intval($containerUid);
        $whereClause .= self::enableFields($fromTable, $showHidden);

        return $GLOBALS['TYPO3_DB']->exec_SELECTgetRows($selectFields, $fromTable, $whereClause);
    }

    /**
     * This function is deprecated. Do not use it.
     *
     * @param int $containerUid
     * @param string $showHidden
     *
     * @return bool
     * @deprecated Use tx_multicolumn_db::getContainerChildren() instead
     */
    public static function containerHasChildren($containerUid, $showHidden = true)
    {
        GeneralUtility::logDeprecatedFunction();

        return self::getContainerChildren($containerUid, $showHidden);
    }

    /**
     * Get enableFields frontend / backend
     *
     * @param string $table table name
     * @param bool $showHidden
     * @param array $ignoreFields
     *
     * @return string
     */
    protected static function enableFields($table, $showHidden = false, $ignoreFields = [])
    {
        if (TYPO3_MODE == 'BE') {
            $enableFields = \TYPO3\CMS\Backend\Utility\BackendUtility::deleteClause($table) . ' AND ' . $table . '.pid>0';
            if (!$showHidden) {
                $enableFields .= \TYPO3\CMS\Backend\Utility\BackendUtility::BEenableFields($table);
            }
        } else {
            $enableFields = $GLOBALS['TSFE']->sys_page->enableFields($table, $showHidden, $ignoreFields);
        }

        return $enableFields;
    }
}
