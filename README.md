# TreeView CMS

TreeView CMS는 PHP와 MySQL로 동작하는 웹 기반 지식 관리 CMS입니다. 문서를 데이터베이스에 저장하고, 카테고리, 태그, `[[문서 링크]]`, 백링크, 검색, 그래프 뷰로 문서 관계를 탐색할 수 있습니다.

## 주요 기능

- 문서 작성, 수정, 삭제
- 다중 카테고리 경로 관리
- Markdown 문서와 제한된 HTML 문서 지원
- `[[문서명]]` 내부 링크와 백링크
- `#태그` 자동 추출
- 카테고리, 태그, 본문 검색
- 공개 대시보드와 관리자 대시보드
- Contact 문의 저장
- About, 이용방침, 개인정보처리방침 문구 관리

## 요구 환경

- PHP 8.1 이상 권장
- MySQL 5.7 이상 또는 MariaDB 10.x 이상
- Apache `mod_rewrite` 권장
- PHP 확장: PDO, pdo_mysql, mbstring, dom

## 파일 구조

```text
TreeView_CMS/
  assets/
    admin.css
    app.js
    style.css
  lib/
    Database.php
    HtmlSanitizer.php
    Markdown.php
    NoteRepository.php
    SettingsRepository.php
    UserRepository.php
  admin.php
  config.php
  config.example.php
  index.php
  install.php
  schema.sql
  category_tables.sql
  sitemap.php
```

## 설치

1. 서버에 파일을 업로드합니다.
2. `config.example.php`를 참고해 `config.php`의 DB 정보를 입력합니다.
3. 하위 폴더에 설치한다면 `BASE_PATH`를 실제 경로로 맞춥니다. 예: `/`
4. 브라우저에서 `/install.php`를 실행합니다.
5. 관리자 이름, 이메일, 비밀번호, Contact 이메일을 입력하고 설치합니다.
6. 설치 후 서버에서 `install.php` 접근을 차단하거나 파일명을 변경합니다.

`config.php` 예시:

```php
const BASE_PATH = '/';
const DB_HOST = 'localhost';
const DB_NAME = 'DB_NAME';
const DB_USER = 'DB_USER';
const DB_PASS = 'DB_PASS';
const DB_CHARSET = 'utf8mb4';
const ADMIN_PASSWORD = 'long-random-secret';
```

`ADMIN_PASSWORD`는 관리자 로그인 비밀번호가 아니라 방문자/문의 해시에 사용하는 내부 salt입니다. 관리자 계정 비밀번호는 설치 화면에서 생성되며 DB에는 해시로 저장됩니다.

## 문서 작성

관리자 로그인 후 `admin.php`에서 문서를 등록합니다.

카테고리는 `/`로 계층을 만듭니다. 한 문서에 여러 경로를 넣을 수 있으며 줄바꿈 또는 `|`로 구분합니다.

```text
개발/프로그래밍언어/PHP
개발/백엔드/PHP
데이터베이스/DBMS/MySQL
```

Markdown 본문 예시:

```markdown
---
title: PHP
category_paths: 개발/프로그래밍언어/PHP | 개발/백엔드/PHP
tags: [PHP, Backend]
---

# PHP

서버 사이드 웹 개발에 널리 쓰이는 스크립트 언어입니다.

## 관련

- [[MySQL]]
- [[WordPress]]
```

## 배포 메모

- GitHub에는 `config.php`를 실제 비밀번호가 없는 템플릿 상태로 유지하는 것이 안전합니다.
- 운영 서버의 실제 DB 정보는 서버에서만 수정하거나, 배포 자동화에서 별도 주입하는 방식을 권장합니다.
- Apache가 아닌 Nginx를 쓰는 경우 `.htaccess`가 적용되지 않으므로 `config.php`, `*.sql`, `.git`, `README.md` 접근 차단을 서버 설정에 직접 추가해야 합니다.
- `install.php`는 설치 후 제거하거나 접근 제한하세요.

## 추가 문서

- [설치 가이드](docs/INSTALL.md)
- [설정 가이드](docs/SETTINGS.md)
- [배포 체크리스트](docs/DEPLOYMENT.md)
- [패치노트](CHANGELOG.md)
