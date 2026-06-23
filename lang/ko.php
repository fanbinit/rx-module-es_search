<?php

$lang->es_search_name = 'ElasticSearch 연동 검색';
$lang->es_search_admin_title = 'ElasticSearch 연동 검색 설정';

$lang->es_search_enabled = 'ES 검색 사용';
$lang->es_search_host = 'ElasticSearch 서버';
$lang->es_search_index = '색인(index) 이름';
$lang->es_search_comment_index = '댓글 색인(index) 이름';
$lang->es_search_auth = '인증 정보';
$lang->es_search_username = '사용자명 (선택)';
$lang->es_search_password_placeholder = '비워두면 기존 값 유지';
$lang->es_search_member_only = '회원만 검색 가능';
$lang->es_search_guest_message = '비회원 검색 시 안내 문구';
$lang->es_search_sync_batch_size = '1회 동기화 처리 건수';
$lang->es_search_sync_interval = '자동 동기화 주기(초)';

$lang->es_search_nori_not_installed = '경고: ElasticSearch에 nori(한국어 형태소 분석) 플러그인이 설치되어 있지 않습니다. 이 상태로는 "흔적기관"처럼 검색어가 포함된 복합어가 "흔적" 검색에 매칭되지 않아, DB 검색보다 검색 결과가 크게 줄어들 수 있습니다. 각 ES 노드에서 "bin/elasticsearch-plugin install analysis-nori"를 실행하고 ES를 재시작한 뒤, 인덱스 및 큐 비우기로 재색인하세요.';

$lang->es_search_sync_status = '색인 동기화 현황';
$lang->es_search_sync_status_documents = '글';
$lang->es_search_sync_status_comments = '댓글';
$lang->es_search_pending_count = '대기 중: %d건';
$lang->es_search_done_count = '성공: %d건';
$lang->es_search_failed_count = '실패: %d건';
$lang->es_search_sync_now = '지금 동기화';
$lang->es_search_flush_now = '인덱스 및 큐 비우기';
$lang->es_search_confirm_flush = 'ElasticSearch 색인과 동기화 큐를 모두 비웁니다. 검색 결과가 비워지며, 다시 채우려면 재색인이 필요합니다. 계속하시겠습니까?';

$lang->es_search_log_document_srl = '문서 번호';
$lang->es_search_log_action = '작업';
$lang->es_search_log_status = '상태';
$lang->es_search_log_regdate = '등록일';
$lang->es_search_log_message = '메시지';

$lang->es_search_index_fields = '색인에 담기는 정보';
$lang->es_search_field = '필드';
$lang->es_search_field_desc = '설명';

$lang->es_search_field_document_srl = '문서 번호';
$lang->es_search_field_module_srl = '게시판(모듈) 번호';
$lang->es_search_field_category_srl = '카테고리 번호';
$lang->es_search_field_member_srl = '글쓴이 회원 번호';
$lang->es_search_field_user_id = '글쓴이 아이디 (별명/아이디 검색용)';
$lang->es_search_field_nick_name = '글쓴이 별명 (별명/아이디 검색용)';
$lang->es_search_field_title = '제목 (한국어 형태소 분석 색인)';
$lang->es_search_field_content_document = '본문 텍스트 (한국어 형태소 분석 색인)';
$lang->es_search_field_status_document = '공개 상태 (공개/비밀 등)';
$lang->es_search_field_regdate = '등록일';
$lang->es_search_field_list_order = '목록 정렬 순서';
$lang->es_search_field_update_order = '글 수정/끌어올리기 정렬 순서';
$lang->es_search_field_readed_count = '조회수';
$lang->es_search_field_voted_count = '추천수';
$lang->es_search_field_comment_count = '댓글수';

$lang->es_search_field_comment_srl = '댓글 번호';
$lang->es_search_field_comment_document_srl = '댓글이 달린 문서 번호';
$lang->es_search_field_comment_category_srl = '댓글이 달린 글의 카테고리 번호 (댓글 테이블엔 없어 글에서 가져와 함께 색인)';
$lang->es_search_field_content_comment = '댓글 내용 텍스트 (한국어 형태소 분석 색인)';
$lang->es_search_field_is_secret = '비밀 댓글 여부 (Y/N, 비회원·일반 검색에서는 비밀 댓글 제외)';
$lang->es_search_field_status_comment = '발행 상태 (발행됨/대기/삭제됨 등)';

$lang->msg_es_search_guest_blocked = '검색 기능은 로그인 후 사용 가능합니다.';
$lang->msg_es_search_sync_done = '%d건의 색인 동기화를 처리했습니다.';
$lang->msg_es_search_flush_done = 'ElasticSearch 색인과 동기화 큐를 모두 비웠습니다.';
