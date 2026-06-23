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
 *   --scan-batch=<n>                 DB에서 한 번에 조회할 문서 수 (기본 500)
 *   --limit=<n>                      등록할 문서 수 제한 (테스트용, 생략 시 전체)
 *   --sync                           등록 후 대기열을 모두 즉시 처리한다 (생략 시 등록만 하고
 *                                    실제 색인은 관리자 화면의 수동 동기화 또는
 *                                    자동 주기 동기화에 맡긴다)
 *   --sync-batch=<n>                 --sync 사용 시 1회 처리(ES Bulk 요청 1번) 건수 (기본 500)
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

$scan_batch = max(1, (int)($options['scan-batch'] ?? 500));
$limit = isset($options['limit']) ? max(1, (int)$options['limit']) : null;
$do_sync = isset($options['sync']);

$status_list = [DocumentModel::getConfigStatus('public'), DocumentModel::getConfigStatus('secret')];

// 전체 대상 문서 수를 미리 구해서 진행률 표시줄의 분모로 쓴다.
$total_documents = SearchLogModel::getDocumentCount($status_list, $module_srl);
if ($limit)
{
	$total_documents = min($total_documents, $limit);
}

echo "Scanning documents" . ($module_srl ? " (module_srl={$module_srl})" : ' (all boards)') . " - {$total_documents} total...\n";

$from_srl = 0;
$scanned = 0;
$enqueued = 0;
$skipped_consultation = 0;

while (true)
{
	$rows = SearchLogModel::getDocumentBatch($status_list, $from_srl, $scan_batch, $module_srl);
	if (!$rows)
	{
		break;
	}

	$batch = [];
	foreach ($rows as $row)
	{
		$from_srl = max($from_srl, (int)$row->document_srl);
		$scanned++;

		// 상담 게시판 글은 작성자/관리자 외에는 비공개이므로 색인 대상에서 제외한다.
		if (EventHandlers::isConsultationModule((int)$row->module_srl))
		{
			$skipped_consultation++;
			continue;
		}

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
echo "Enqueued {$enqueued} documents for indexing" . ($skipped_consultation ? " ({$skipped_consultation} consultation-board documents skipped)" : '') . ".\n";

// Optionally process the queue immediately.
if ($do_sync)
{
	// 대량 Bulk 요청 처리 중 응답이 일부 누락되어 실패로 남은 항목들을 재시도 대상으로 되돌린다.
	SearchLogModel::requeueFailed();

	$sync_batch = max(1, (int)($options['sync-batch'] ?? 500));
	// getPendingCount()는 방금 등록한 $enqueued건을 이미 포함하고 있으므로 더해서는 안 된다
	// (더하면 분모가 거의 두 배가 되어, 실제로는 다 처리됐는데도 진행률이 50%대에서 멈춘 것처럼 보인다).
	$sync_total = SearchLogModel::getPendingCount();

	echo "Processing the sync queue (bulk batch size {$sync_batch})...\n";
	$total_processed = 0;
	$stalled_rounds = 0;
	$prev_pending = $sync_total;

	while (($processed = EventHandlers::processPendingLogs($sync_batch)) > 0)
	{
		$total_processed += $processed;
		printProgress('Indexing', $total_processed, $sync_total);

		// 응답 누락 등으로 같은 항목이 계속 pending으로 남아 처리량이 줄지 않으면
		// (예: ES가 응답을 못하는 상태) 무한 루프에 빠지지 않도록 몇 차례 후 중단한다.
		$current_pending = SearchLogModel::getPendingCount();
		if ($current_pending >= $prev_pending)
		{
			$stalled_rounds++;
			if ($stalled_rounds >= 5)
			{
				endProgress();
				echo "Warning: no progress after {$stalled_rounds} rounds, stopping early. {$current_pending} documents still pending - check ES connectivity and re-run later.\n";
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
	echo "Done. {$total_processed} documents indexed.\n";

	$failed = SearchLogModel::getFailedCount();
	if ($failed)
	{
		echo "Warning: {$failed} documents failed to index. Check the admin screen for details.\n";
	}
}
else
{
	echo "Run with --sync to index immediately, or use the admin screen / automatic sync.\n";
}
