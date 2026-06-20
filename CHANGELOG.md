# Changelog

TreeView CMS의 버전별 변경사항을 기록합니다.

## v0.3.0 - 2026-06-20

### Added

- 문서별 좋아요 기능 추가
- 방문자별 중복 좋아요 방지를 위한 `note_likes` 테이블 추가
- 문서 상단 공유 버튼 추가
- 공개/관리자 대시보드에 전체 좋아요 수 표시
- 좋아요 버튼 AJAX 처리와 즉시 카운트 갱신 추가
- 좋아요/공유 버튼 디자인 개선

### Fixed

- 일부 MySQL/NAS 환경에서 `note_likes` 외래키 생성 실패 또는 테이블 미생성으로 문서 상세 페이지가 500 오류를 내던 문제 수정

## v0.2.1 - 2026-06-20

### Fixed

- PHP 7.0 서버에서 `index.php` 업로드 후 500 오류가 발생할 수 있던 문법 호환성 문제 수정
- `?type`, `void`, typed property, arrow function, `private const` 문법을 PHP 7.0 호환 형태로 변경
- PHP 파일의 UTF-8 BOM을 제거해 `declare(strict_types=1)` 파싱 오류 방지
- Apache 2.2 서버에서 `.htaccess`의 `Require all denied` 문법 때문에 500 오류가 발생할 수 있던 문제 수정
- `config.php`에서 불필요한 `declare(strict_types=1)`를 제거해 BOM이 붙은 업로드 환경에서도 fatal error가 나지 않도록 수정

## v0.2.0 - 2026-06-20

### Added

- 공식 문서 추가: 설치, 설정, 배포 체크리스트
- `config.example.php` 추가
- `robots.php` 추가: `BASE_PATH` 기준 robots 출력
- `.gitignore` 추가

### Changed

- README를 실제 배포 구조에 맞게 재작성
- `install.php`가 `schema.sql`과 `category_tables.sql`을 모두 적용하도록 변경
- `BASE_PATH`를 canonical URL, sitemap, robots 출력에 반영
- About, Terms, Privacy, Contact 개별 PHP 파일을 `index.php?page=...` 라우트로 리다이렉트
- 공개 회원가입을 첫 관리자 계정 생성용으로 제한
- Contact 문의 접수에 10분당 3회 제한 추가
- `config.php`의 `ADMIN_PASSWORD` 설명을 내부 salt 용도로 수정

### Security

- `.htaccess`에서 `.git`, SQL 파일, `config.php`, debug 파일 접근 차단 강화
- 기본 보안 헤더 추가
- 배포본에서 `debug_note.php` 제거
- DB 설정 누락 시 명확한 오류 메시지 출력

### Notes

- `category_tables.sql`의 카테고리 테이블 정의는 `schema.sql`에도 포함되어 있습니다. 설치기는 두 파일을 모두 읽지만 `CREATE TABLE IF NOT EXISTS`로 처리되어 중복 실행에 안전합니다.
- 운영 서버에서는 설치 후 `install.php`를 삭제하거나 접근 제한해야 합니다.
