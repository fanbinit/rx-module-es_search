<?php

/**
 * es_search 모듈의 초기(전체) ElasticSearch 색인 적재 스크립트.
 *
 * 기존 문서는 es_search 모듈 설치 이후에 작성/수정된 글이 아니므로
 * es_search_log에 자동으로 쌓이지 않는다. 이 스크립트를 한 번 실행하여
 * 기존 문서 전체를 동기화 대기 상태로 등록하고, 필요하면 즉시 색인까지 처리한다.
 *
 * 사용법:
 *   php modules/es_search/scripts/reindex.php [options]
 *
 * 옵션:
 *   --module=<mid 또는 module_srl>   특정 게시판만 대상으로 한다 (생략 시 전체 게시판)
 *   --consultation-only              상담 게시판으로 설정된 게시판만 대상으로 한다.
 *                                    --module과 같이 쓰면 그 게시판이 상담 게시판일 때만
 *                                    동작한다 (--module 단독으로는 일반 게시판도 가능하지만,
 *                                    이 옵션은 상담 게시판 전용 스캔이다).
 *   --target=document|comment|all    스캔(대기열 등록) 대상을 글/댓글 중 하나로 한정한다
 *                                    (생략 시 all - 둘 다 처리). --sync-only와 함께 쓰면 무시된다.
 *   --scan-batch=<n>                 DB에서 한 번에 조회할 행 수 (기본 500)
 *   --limit=<n>                      등록할 건수 제한 (테스트용, 생략 시 전체)
 *   --sync                           스캔 후 대기열을 모두 즉시 처리한다 (생략 시 등록만 하고
 *                                    실제 색인은 관리자 화면의 수동 동기화 또는
 *                                    자동 주기 동기화에 맡긴다)
 *   --sync-only                      스캔(대기열 등록) 단계를 건너뛰고, 이미 쌓여 있는
 *                                    대기열만 즉시 처리한다 (--target/--module/--limit 무시,
 *                                    --sync도 자동으로 켜진 것으로 취급한다)
 *   --sync-batch=<n>                 --sync 또는 --sync-only 사용 시 1회 처리(ES Bulk 요청 1번) 건수 (기본 500)
 */
require_once __DIR__ . '/../../../common/scripts/common.php';

use Rhymix\Modules\Es_search\Controllers\EventHandlers;
use Rhymix\Modules\Es_search\Models\SearchLog as SearchLogModel;

/**
 * 같은 줄에 진행률 표시줄을 갱신해서 출력한다 (\r 사용).
 *
 * @param string $label
 * @param int $current
 * @param int $total
 * @return void
 */
function printProgress(string $label, int $current, int $total): void
{
	$total = max($total, $current, 1);
	$percent = (int)floor(($current / $total) * 100);
	$width = 30;
	$filled = (int)floor($width * $percent / 100);
	$bar = str_repeat('#', $filled) . str_repeat('-', $width - $filled);
	$line = sprintf('%s [%s] %3d%% (%d/%d)', $label, $bar, $percent, $current, $total);
	// 이전 줄보다 짧게 찍힐 경우 잔여 글자가 남지 않도록 충분히 공백으로 덮어쓴다.
	fwrite(STDOUT, "\r" . $line . str_repeat(' ', 20));
}

/**
 * 진행률 표시줄을 끝내고 다음 출력을 새 줄에서 시작하도록 개행한다.
 *
 * @return void
 */
function endProgress(): void
{
	fwrite(STDOUT, "\n");
}

// Parse CLI options.
$options = [];
foreach (array_slice($argv, 1) as $arg)
{
	if (preg_match('/^--([a-z\-]+)(?:=(.*))?$/', $arg, $matches))
	{
		$options[$matches[1]] = $matches[2] ?? true;
	}
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

// --consultation-only: 상담 게시판으로 설정된 게시판(들)만 스캔 대상으로 한정한다.
$consultation_only = isset($options['consultation-only']);
if ($consultation_only)
{
	$consultation_srls = EventHandlers::getConsultationModuleSrls();
	if ($module_srl)
	{
		if (!in_array($module_srl, $consultation_srls))
		{
			echo "Error: module '{$options['module']}' is not a consultation board.\n";
			exit(1);
		}
		$module_filter = $module_srl;
	}
	else
	{
		if (!$consultation_srls)
		{
			echo "No consultation-enabled boards found. Nothing to do.\n";
			exit(0);
		}
		$module_filter = $consultation_srls;
	}
	echo "Consultation-only mode: targeting module_srl " . implode(',', (array)$module_filter) . "\n";
}
else
{
	$module_filter = $module_srl;
}

$scan_batch = max(1, (int)($options['scan-batch'] ?? 500));
$limit = isset($options['limit']) ? max(1, (int)$options['limit']) : null;
$sync_only = isset($options['sync-only']);
$do_sync = $sync_only || isset($options['sync']);

$target = (string)($options['target'] ?? 'all');
if (!in_array($target, ['document', 'comment', 'all']))
{
	echo "Error: --target must be one of document, comment, all.\n";
	exit(1);
}
$scan_documents = !$sync_only && ($target === 'document' || $target === 'all');
$scan_comments = !$sync_only && ($target === 'comment' || $target === 'all');

$status_list = [DocumentModel::getConfigStatus('public'), DocumentModel::getConfigStatus('secret')];

if ($scan_documents)
{

// 전체 대상 문서 수를 미리 구해서 진행률 표시줄의 분모로 쓴다.
$total_documents = SearchLogModel::getDocumentCount($status_list, $module_filter);
if ($limit)
{
	$total_documents = min($total_documents, $limit);
}

echo "Scanning documents" . ($module_filter ? " (module_srl=" . implode(',', (array)$module_filter) . ")" : ' (all boards)') . " - {$total_documents} total...\n";

$from_srl = 0;
$scanned = 0;
$enqueued = 0;

while (true)
{
	$rows = SearchLogModel::getDocumentBatch($status_list, $from_srl, $scan_batch, $module_filter);
	if (!$rows)
	{
		break;
	}

	$batch = [];
	foreach ($rows as $row)
	{
		$from_srl = max($from_srl, (int)$row->document_srl);
		$scanned++;

		// 상담 게시판 글도 색인한다 ("작성 글 보기"에서 찾을 수 있도록 - 일반 검색 노출은
		// ElasticModel 쪽에서 따로 막는다).
		$batch[] = ['module_srl' => (int)$row->module_srl, 'document_srl' => (int)$row->document_srl, 'action' => 'update'];
		$enqueued++;

		if ($limit && $enqueued >= $limit)
		{
			break;
		}
	}

	// 한 건씩 등록하지 않고 트랜잭션으로 묶어서 한 번에 등록한다 (속도 개선).
	SearchLogModel::markPendingBatch($batch);

	printProgress('Scanning', $scanned, $total_documents);

	if ($limit && $enqueued >= $limit)
	{
		break;
	}
}

endProgress();
echo "Enqueued {$enqueued} documents for indexing.\n";

}

// 댓글도 동일한 방식으로 스캔해서 등록한다 (댓글 검색/내 댓글 검색이 ES로 동작하려면 필요).
if ($scan_comments)
{

$total_comments = SearchLogModel::getCommentCount(RX_STATUS_PUBLIC, $module_filter);
if ($limit)
{
	$total_comments = min($total_comments, $limit);
}

echo "\nScanning comments" . ($module_filter ? " (module_srl=" . implode(',', (array)$module_filter) . ")" : ' (all boards)') . " - {$total_comments} total...\n";

$from_srl = 0;
$scanned = 0;
$enqueued = 0;

while (true)
{
	$rows = SearchLogModel::getCommentBatch(RX_STATUS_PUBLIC, $from_srl, $scan_batch, $module_filter);
	if (!$rows)
	{
		break;
	}

	$batch = [];
	foreach ($rows as $row)
	{
		$from_srl = max($from_srl, (int)$row->comment_srl);
		$scanned++;

		// document_srl은 모르는 채로도 동기화는 가능하다 (processPendingCommentLogs가
		// 댓글을 다시 읽어와 document_srl/category_srl을 직접 확인하기 때문). 상담 게시판
		// 댓글도 색인한다 ("작성 댓글 보기"에서 찾을 수 있도록).
		$batch[] = ['module_srl' => (int)$row->module_srl, 'document_srl' => 0, 'comment_srl' => (int)$row->comment_srl, 'action' => 'update'];
		$enqueued++;

		if ($limit && $enqueued >= $limit)
		{
			break;
		}
	}

	SearchLogModel::markCommentPendingBatch($batch);

	printProgress('Scanning comments', $scanned, $total_comments);

	if ($limit && $enqueued >= $limit)
	{
		break;
	}
}

endProgress();
echo "Enqueued {$enqueued} comments for indexing.\n";

}

if ($sync_only)
{
	echo "Skipping scan (--sync-only); processing existing sync queue only.\n";
}

if ($do_sync)
{
	SearchLogModel::requeueFailed();

	$sync_batch = max(1, (int)($options['sync-batch'] ?? 500));
	$sync_total = SearchLogModel::getPendingCount();

	echo "Processing the sync queue (bulk batch size {$sync_batch})...\n";
	$total_processed = 0;
	$stalled_rounds = 0;
	$prev_pending = $sync_total;

	while (($processed = EventHandlers::processPendingLogs($sync_batch)) > 0)
	{
		$total_processed += $processed;
		printProgress('Indexing', $total_processed, $sync_total);

		$current_pending = SearchLogModel::getPendingCount();
		if ($current_pending >= $prev_pending)
		{
			$stalled_rounds++;
			if ($stalled_rounds >= 5)
			{
				endProgress();
				echo "Warning: no progress after {$stalled_rounds} rounds, stopping early. {$current_pending} items still pending - check ES connectivity and re-run later.\n";
				break;
			}
		}
		else
		{
			$stalled_rounds = 0;
		}
		$prev_pending = $current_pending;
	}
	endProgress();
	echo "Done. {$total_processed} items indexed.\n";

	$failed = SearchLogModel::getFailedCount();
	if ($failed)
	{
		echo "Warning: {$failed} items failed to index. Check the admin screen for details.\n";
	}
}
else
{
	echo "Run with --sync to index immediately, or use the admin screen / automatic sync.\n";
}
