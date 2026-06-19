# Installation

## 1. 환경 확인

TreeView CMS는 PHP와 MySQL이 필요합니다.

- PHP 8.1 이상 권장
- MySQL 5.7 이상 또는 MariaDB 10.x 이상
- PHP 확장: PDO, pdo_mysql, mbstring, dom
- Apache 사용 시 `mod_rewrite` 권장

## 2. 데이터베이스 생성

호스팅 패널 또는 MySQL 콘솔에서 빈 데이터베이스와 DB 사용자를 만듭니다.

```sql
CREATE DATABASE treeview CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'treeview_user'@'localhost' IDENTIFIED BY 'change-this-password';
GRANT ALL PRIVILEGES ON treeview.* TO 'treeview_user'@'localhost';
FLUSH PRIVILEGES;
```

공유 호스팅은 DB 생성 권한이 제한될 수 있으므로 호스팅 패널에서 제공하는 DB 이름과 계정을 사용하세요.

## 3. config.php 설정

`config.example.php`를 기준으로 `config.php`를 수정합니다.

```php
const BASE_PATH = '/';
const DB_HOST = 'localhost';
const DB_NAME = 'DB_NAME';
const DB_USER = 'DB_USER';
const DB_PASS = 'DB_PASS';
const DB_CHARSET = 'utf8mb4';
const ADMIN_PASSWORD = 'long-random-secret';
```

`BASE_PATH`는 설치 경로입니다.

- 도메인 루트: `/`
- `https://example.com/`: `/`
- `https://example.com/web/`: `/web/`

## 4. 설치 실행

브라우저에서 설치 파일을 엽니다.

```text
https://example.com/install.php
```

설치 화면에서 관리자 계정과 Contact 이메일을 입력합니다. 설치가 완료되면 기본 테이블, 기본 문서, 사이트 설정이 생성됩니다.

설치기는 `schema.sql`을 먼저 적용한 뒤 `category_tables.sql`을 한 번 더 적용합니다. 카테고리 테이블 정의는 중복되어 있어도 `CREATE TABLE IF NOT EXISTS`로 처리되므로 기존 설치에는 영향을 주지 않습니다.

## 5. 설치 후 확인

- `index.php`에서 메인 화면이 열리는지 확인
- 계정 버튼에서 관리자 로그인 확인
- `admin.php`에서 문서 등록 확인
- Contact 페이지에서 문의 접수 확인
- 카테고리 페이지와 sitemap 확인

## 6. 설치 파일 처리

운영 서버에서는 설치 후 `install.php`를 삭제하거나 접근 제한하세요. GitHub 저장소에는 유지해도 되지만, 실제 서버에서 누구나 실행할 수 있으면 안 됩니다.
