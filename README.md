# es_search

라이믹스(Rhymix) 게시판 검색을 ElasticSearch로 대체하는 모듈입니다. DB의 `LIKE` 검색 대신
ElasticSearch의 형태소 분석 기반 검색을 사용해, 대용량 게시판에서도 빠르고 정확한 검색을
제공합니다.

## 실행 환경

- 라이믹스(Rhymix) 2.1.3 이상
- PHP 8.1.0 이상
- ElasticSearch를 설치하고 구동할 수 있는 서버 환경
- ElasticSearch 한국어 형태소 분석기 플러그인 (analysis-nori)

## 주요 기능

- **검색 가로채기**: `search_target`이 `title`/`content`/`title_content`/`comment`/
  `member_srl`/`nick_name`/`user_id`/`regdate`인 게시판 검색 요청을 ElasticSearch 결과로
  대체합니다(글쓴이 회원번호 정확 일치, 별명/사용자ID 부분 일치, 등록일 앞부분 일치,
  댓글 내용 검색 등). ES 연결에 실패하면 예외를 잡아 기존 DB 검색으로 자동 전환(폴백)합니다.
- **댓글 색인/검색**: 댓글도 문서와 별도의 ES 인덱스(`es_comment_index`)에 색인됩니다.
  `search_target=comment` 게시판 검색(댓글이 달린 글을 중복 없이 표시)과, "내 댓글 보기"
  같은 `comment.getTotalCommentList` 댓글 본문 검색을 모두 ES로 대체합니다. 비밀글에 달린
  댓글은 비밀댓글로 간주되어, 해당 게시판 매니저가 아니거나 본인 글/댓글 검색이 아니면
  검색 결과에서 제외됩니다.
- **한국어 형태소 분석(nori)**: 색인 생성 시 `nori_tokenizer`(decompound_mode=mixed)를 쓰는
  분석기를 자동으로 적용합니다. 이를 통해 "흔적기관", "흔적을"처럼 검색어가 포함된 복합어/조사
  결합형도 "흔적" 검색에 매칭됩니다. nori_tokenizer 미설치시 검색결과 누락이 매우 많아지므로
  설치하시는것을 강력히 권장드립니다.
- **자동 동기화**: 문서/댓글 등록/수정/삭제 시 동기화 대기열(`es_search_log`)에 기록되고, 매
  요청마다 가볍게 확인해 일정 주기(`sync_interval_seconds`)가 지나면 대기 중인 항목을
  소량(`sync_batch_size`)씩 ES Bulk API로 색인합니다. 별도의 crontab 없이 동작하는
  소프트 스케줄러입니다.
- **관리자 화면**: ES 서버 연결 정보, 동기화 주기/배치 크기 설정, 문서/댓글 각각의 동기화
  현황(대기/성공/실패 건수)과 최근 처리 로그 확인, 수동 동기화 및 인덱스/큐 초기화(flush)
  기능을 제공합니다.
- **회원 전용 검색 옵션**: 비회원의 검색을 막고 안내 문구를 보여줄 수 있습니다.

## 동작 방식

1. 문서/댓글이 등록/수정/삭제되면 이벤트 핸들러가 `es_search_log` 테이블에 동기화 대기 항목을
   남깁니다.
2. 매 요청마다(`moduleHandler.init` 이후) 마지막 동기화 시각으로부터 설정된 주기가
   지났는지 확인하고, 지났으면 대기 중인 항목을 일정량 처리합니다. 처리는 ES Bulk API를
   사용해 문서/댓글을 각각 한 번에 색인/삭제합니다.
3. 게시판에서 제목/본문/댓글/글쓴이/별명/사용자ID/등록일 검색이 요청되면
   `document.getDocumentList`(또는 댓글 본문 검색의 경우 `comment.getTotalCommentList`)
   트리거가 가로채어 ElasticSearch에 질의하고, 결과를 DocumentModel/CommentModel의 객체로
   다시 채워 기존 게시판 화면과 동일한 형태로 반환합니다.
4. ES 호출이 예외를 던지면(연결 실패 등) 트리거가 결과를 대체하지 않고 그대로 반환해,
   라이믹스 기본 DB 검색이 그대로 동작합니다.

## 관리자 설정 (`dispEs_searchAdminConfig`)

| 설정 | 설명 |
|---|---|
| ES 검색 사용 | 모듈 전체 동작 여부 |
| ElasticSearch 서버 | scheme(http/https) / host / port |
| 색인(index) 이름 | 문서를 저장할 ES 인덱스명 |
| 댓글 색인(index) 이름 | 댓글을 저장할 ES 인덱스명 (문서 인덱스와 분리) |
| 인증 정보 | Basic Auth 사용자명/비밀번호 (선택) |
| 회원만 검색 가능 | 비회원 검색 차단 여부 |
| 비회원 검색 시 안내 문구 | 차단 시 보여줄 메시지 |
| 1회 동기화 처리 건수 | 소프트 스케줄러가 한 번에 처리할 문서 수 |
| 자동 동기화 주기(초) | 다음 동기화까지 최소 대기 시간 |

설정 화면에서는 nori 플러그인 미설치가 감지되면 ES 연동 설정 위에 경고 메시지가 표시됩니다.

## CLI 스크립트

- `scripts/reindex.php`: 모듈 설치 이전에 작성된 기존 문서/댓글을 동기화 대기 상태로 등록하고,
  `--sync` 옵션으로 즉시 색인까지 처리할 수 있습니다. `--target=document|comment|all`로 대상을
  한정하거나, `--module`로 특정 게시판만, `--consultation-only`로 상담 게시판만 대상으로 할 수
  있습니다.
- `scripts/compare_search.php`: 특정 검색어에 대해(`search_target=title_content` 기준) DB
  검색과 ES 검색 결과를 비교해, ES가 놓친 글이나 DB에 없는 글을 진단합니다.

각 스크립트는 `--help` 없이도 옵션 설명이 파일 상단 주석에 있으며, `php modules/es_search/scripts/<script>.php` 형태로 실행합니다.

## 설치

이 모듈은 `vendor` 디렉터리(Elasticsearch PHP 클라이언트 등 의존 라이브러리)를 포함해 배포되므로
`composer install` 없이 그대로 업로드해서 사용할 수 있습니다. 다만 검색 대상인 ElasticSearch
서버는 별도로 설치/구동해야 합니다.

### ElasticSearch 서버 설치

라이믹스 게시판 하나를 색인하는 정도의 단일 서버 환경에서는 가장 최근 LTS 버전의 ElasticSearch와
한국어 형태소 분석을 위한 `analysis-nori` 플러그인을 함께 설치하면 됩니다.

#### Debian / Ubuntu 계열

```bash
# 1) 공식 GPG 키와 저장소 등록
sudo apt-get update
sudo apt-get install -y apt-transport-https gnupg
wget -qO - https://artifacts.elastic.co/GPG-KEY-elasticsearch | sudo gpg --dearmor -o /usr/share/keyrings/elasticsearch-keyring.gpg
echo "deb [signed-by=/usr/share/keyrings/elasticsearch-keyring.gpg] https://artifacts.elastic.co/packages/8.x/apt stable main" | sudo tee /etc/apt/sources.list.d/elastic-8.x.list

# 2) 설치
sudo apt-get update
sudo apt-get install -y elasticsearch

# 3) 한국어 형태소 분석 플러그인 설치
sudo /usr/share/elasticsearch/bin/elasticsearch-plugin install analysis-nori

# 4) 서비스 등록 및 실행
sudo systemctl daemon-reload
sudo systemctl enable --now elasticsearch
```

#### RHEL / CentOS / Rocky Linux 계열

```bash
# 1) 공식 GPG 키 등록
sudo rpm --import https://artifacts.elastic.co/GPG-KEY-elasticsearch

# 2) 저장소 등록 (/etc/yum.repos.d/elasticsearch.repo)
cat <<'EOF' | sudo tee /etc/yum.repos.d/elasticsearch.repo
[elasticsearch]
name=Elasticsearch repository for 8.x packages
baseurl=https://artifacts.elastic.co/packages/8.x/yum
gpgcheck=1
gpgkey=https://artifacts.elastic.co/GPG-KEY-elasticsearch
enabled=1
autorefresh=1
type=rpm-md
EOF

# 3) 설치
sudo dnf install -y elasticsearch    # 또는 yum install -y elasticsearch

# 4) 한국어 형태소 분석 플러그인 설치
sudo /usr/share/elasticsearch/bin/elasticsearch-plugin install analysis-nori

# 5) 서비스 등록 및 실행
sudo systemctl daemon-reload
sudo systemctl enable --now elasticsearch
```

#### Windows Server

1. [Elastic 공식 다운로드 페이지](https://www.elastic.co/downloads/elasticsearch)에서 Windows용
   `.zip` 배포판을 내려받아 원하는 경로(예: `C:\Elastic\elasticsearch`)에 압축을 풉니다.
2. 한국어 형태소 분석 플러그인을 설치합니다. (관리자 권한 PowerShell/명령 프롬프트에서 실행)

   ```powershell
   cd C:\Elastic\elasticsearch\bin
   elasticsearch-plugin.bat install analysis-nori
   ```

3. Windows 서비스로 등록해 서버 재시작 시에도 자동으로 실행되도록 합니다.

   ```powershell
   cd C:\Elastic\elasticsearch\bin
   elasticsearch-service.bat install
   elasticsearch-service.bat start
   ```

   서비스 등록 없이 임시로 띄워 테스트만 하려면 `elasticsearch.bat`를 직접 실행해도 됩니다.

### 계정(아이디/비밀번호) 설정

ElasticSearch는 8.x부터 기본적으로 보안 기능(TLS, 인증)이 활성화되어 설치됩니다. 설치 중 콘솔에
출력되는 `elastic` 계정의 초기 비밀번호를 기록해 두거나, 아래처럼 직접 재설정할 수 있습니다.

#### Linux (Debian/RHEL 공통)

```bash
# elastic 기본 계정 비밀번호를 새로 설정(대화형으로 입력받음)
sudo /usr/share/elasticsearch/bin/elasticsearch-reset-password -u elastic

# 모듈 연동 등에서 쓸 별도 계정을 새로 만들고 권한을 부여하려면
sudo /usr/share/elasticsearch/bin/elasticsearch-users useradd <아이디> -p <비밀번호> -r superuser
```

#### Windows Server

관리자 권한 PowerShell/명령 프롬프트에서 설치 디렉터리의 `bin`으로 이동해 동일한 도구를 사용합니다.

```powershell
cd C:\Elastic\elasticsearch\bin

REM elastic 기본 계정 비밀번호 재설정
elasticsearch-reset-password.bat -u elastic

REM 별도 계정 생성
elasticsearch-users.bat useradd <아이디> -p <비밀번호> -r superuser
```

위에서 설정한 아이디/비밀번호를 관리자 설정 화면(`dispEs_searchAdminConfig`)의 "인증 정보"
항목에 입력하면 모듈이 Basic Auth로 ElasticSearch에 연결합니다. 테스트 환경에서 보안 기능을
끄고 싶다면 `config/elasticsearch.yml`에서 `xpack.security.enabled: false`로 설정할 수
있지만, 운영 환경에서는 권장하지 않습니다.
