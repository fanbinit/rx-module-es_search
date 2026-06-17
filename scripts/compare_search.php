<?php

/**
 * DB 검색과 ElasticSearch 검색 결과(search_target=title_content 기준)를 비교하는 진단 스크립트.
 *
 * 사용법:
 *   php modules/es_search/scripts/compare_search.php <검색어> [options]
 *
 * 옵션:
 *   --module=<mid 또는 module_srl>   특정 게시판만 비교 (생략 시 전체 게시판)
 *   --limit=<n>                      비교할 최대 결과 수 (기본 5000, DB/ES 동일하게 적용)
 *
 * 비고:
 *   - "계속 검색"(division)은 board.view.php가 UI에 표시할지 결정하는 플래그일 뿐,
 *     쿼리 자체는 항상 한 번에 list_count만큼 가져온다. 따라서 DocumentModel::_setSearchOption()이
 *     만든 조건으로 단일 쿼리를 실행하면 division 없이 전체 결과를 그대로 비교할 수 있다.
 *   - DocumentModel::getDocumentList()를 거치면 es_search 모듈 자신의 before 트리거가
 *     DB 결과를 ES 결과로 바꿔치기해버리므로, 비교를 위해 DB 쪽은 _setSearchOption()이
 *     만든 쿼리를 직접 실행해서 트리거를 우회한다.
 *   - 로그인하지 않은 상태로 실행되므로 DocumentModel::_setSearchOption()이 비밀글을
 *     제외한다 (비회원 검색과 동일한 조건). 공정한 비교를 위해 ES 쪽도 동일하게
 *     공개글(PUBLIC)만 남기고 비교한다.
 */
require_once __DIR__ . '/../../../common/scripts/common.php';

use Rhymix\Modules\Es_search\Models\Elastic as ElasticModel;

// Parse CLI options.
$options = [];
$keyword = null;
foreach (array_slice($argv, 1) as $arg)
{
	if (preg_match('/^--([a-z\-]+)(?:=(.*))?$/', $arg, $matches))
	{
		$options[$matches[1]] = $matches[2] ?? true;
	}
	elseif ($keyword === null)
	{
		$keyword = $arg;
	}
}

if ($keyword === null || $keyword === '')
{
	echo "Usage: php modules/es_search/scripts/compare_search.php <keyword> [--module=mid|srl] [--limit=n]\n";
	exit(1);
}

$module_srl = null;
if (!empty($options['module']))
{
	$module_srl = ctype_digit((string)$options['module'])
		? (int)$options['module']
		: ModuleModel::getModuleSrlByMid((string)$options['module']);
	if (!$module_srl)
	{
		echo "Error: module '{$options['module']}' not found.\n";
		exit(1);
	}
}

$limit = max(1, (int)($options['limit'] ?? 5000));

// 게시판 검색 화면이 만드는 것과 동일한 검색 조건을 만든다.
$searchOpt = new stdClass;
$searchOpt->search_target = 'title_content';
$searchOpt->search_keyword = $keyword;
$searchOpt->module_srl = $module_srl;
$searchOpt->list_count = $limit;
$searchOpt->page = 1;
$searchOpt->page_count = 10;
$searchOpt->sort_index = 'list_order';
$searchOpt->order_type = 'asc';

// --- DB 검색 ---
// document.getDocumentList의 before 트리거(es_search의 ES 가로채기)를 거치지 않도록,
// DocumentModel::getDocumentList()를 호출하지 않고 _setSearchOption()의 결과를 직접 실행한다.
$db_args = null;
$query_id = null;
$use_division = null;
DocumentModel::_setSearchOption($searchOpt, $db_args, $query_id, $use_division);
$db_output = executeQueryArray($query_id, $db_args, ['document_srl', 'title']);

$db_rows = $db_output->toBool() ? ($db_output->data ?: []) : [];
$db_total = (int)($db_output->total_count ?? count($db_rows));

$db_by_srl = [];
foreach ($db_rows as $row)
{
	$db_by_srl[(int)$row->document_srl] = (string)$row->title;
}

// --- ES 검색 ---
$es_output = ElasticModel::search($searchOpt);
$es_total = (int)($es_output->total_count ?? 0);

$public_status = DocumentModel::getConfigStatus('public');
$es_by_srl = [];
$es_secret_skipped = 0;
foreach ($es_output->data as $oDocument)
{
	// DB 쪽은 비로그인 검색이라 비밀글이 제외되므로, 공정한 비교를 위해 ES 쪽도 공개글만 남긴다.
	if ($oDocument->get('status') !== $public_status)
	{
		$es_secret_skipped++;
		continue;
	}
	$es_by_srl[(int)$oDocument->document_srl] = (string)$oDocument->getTitleText();
}

// --- 비교 출력 ---
echo "검색어: \"{$keyword}\" (search_target=title_content)" . ($module_srl ? " / module_srl={$module_srl}" : ' / 전체 게시판') . "\n";
echo "DB 검색 결과: 총 {$db_total}건 (조회됨: " . count($db_by_srl) . "건, 비밀글 제외)\n";
echo "ES 검색 결과: 총 {$es_total}건 (조회됨: " . count($es_output->data) . "건, 공개글만 비교: " . count($es_by_srl) . "건, 비밀글 {$es_secret_skipped}건 제외)\n";

if ($db_total > count($db_by_srl) || $es_total > count($es_output->data))
{
	echo "주의: --limit({$limit})보다 결과가 많아 일부만 가져왔습니다. --limit을 늘려서 다시 실행하세요.\n";
}

$only_in_db = array_diff_key($db_by_srl, $es_by_srl);
$only_in_es = array_diff_key($es_by_srl, $db_by_srl);
$in_both = array_intersect_key($db_by_srl, $es_by_srl);

echo "\n공통(양쪽 다 검색됨): " . count($in_both) . "건\n";

echo "\n[DB에만 있음 - ES가 놓친 글] " . count($only_in_db) . "건\n";
foreach ($only_in_db as $srl => $title)
{
	echo "  - #{$srl}: {$title}\n";
}

echo "\n[ES에만 있음 - DB 검색에는 안 나온 글] " . count($only_in_es) . "건\n";
foreach ($only_in_es as $srl => $title)
{
	echo "  - #{$srl}: {$title}\n";
}
