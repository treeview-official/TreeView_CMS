# Settings

## config.php

`config.php`는 서버별 설정 파일입니다.

| 항목 | 설명 |
| --- | --- |
| `APP_NAME` | DB 설정이 없을 때 사용하는 기본 사이트 이름 |
| `BASE_PATH` | 설치 경로 |
| `DB_HOST` | DB 서버 주소 |
| `DB_NAME` | DB 이름 |
| `DB_USER` | DB 사용자 |
| `DB_PASS` | DB 비밀번호 |
| `DB_CHARSET` | DB 문자셋. 기본값은 `utf8mb4` |
| `ADMIN_PASSWORD` | 방문자/문의 해시용 내부 salt |
| `APP_TIMEZONE` | PHP 시간대 |

`ADMIN_PASSWORD`는 로그인 비밀번호가 아닙니다. 관리자 비밀번호는 설치 화면에서 생성되며 `users.password_hash`에 해시로 저장됩니다.

## 관리자 설정

관리자 로그인 후 `admin.php`에서 다음 항목을 수정할 수 있습니다.

- Contact 이메일
- 사이트 이름
- 파비콘 이미지
- Head 추가 코드
- 사이드바 방문자 위젯 표시 여부
- 상단 공개 대시보드 버튼 표시 여부
- 푸터 요약
- 푸터 하단 안내
- About 본문
- 이용방침 본문
- 개인정보처리방침 본문
- Contact 안내 문구

설정 값은 `site_settings` 테이블에 저장됩니다.

이미지 관리 탭에서 업로드한 이미지는 `uploads/images/YYYY/MM/*.webp` 경로에 WebP로 변환 저장됩니다. 파비콘도 같은 저장소를 사용합니다.

Head 추가 코드는 프론트 `index.php`의 `</head>` 직전에 그대로 출력됩니다. 광고 스크립트, 사이트 인증 `meta`, 외부 CSS `link`처럼 관리자만 관리하는 코드를 넣을 때 사용하세요.

## 카테고리

카테고리는 `/`로 계층을 구분합니다.

```text
개발/프로그래밍언어/PHP
개발/백엔드/PHP
데이터베이스/DBMS/MySQL
```

한 문서가 여러 범주에 속할 수 있습니다. 문서 등록/수정 화면의 카테고리 입력란에 줄바꿈 또는 `|`로 여러 경로를 입력합니다.

```text
개발/프로그래밍언어/PHP
개발/백엔드/PHP
개발/CMS/WordPress
```

이미 존재하는 하위 카테고리 이름이 여러 위치에 있으면 짧은 이름만으로는 구분할 수 없습니다. 이 경우 전체 경로를 입력해야 합니다.

## Markdown 메타데이터

Markdown 문서는 front matter를 사용할 수 있습니다.

```markdown
---
title: MySQL
category_paths: 데이터베이스/DBMS/MySQL | 개발/백엔드/MySQL
tags: [MySQL, Database]
---
```

공개 화면에서는 `---` 메타데이터와 `## 메모` 이후 내용이 노출되지 않도록 Markdown 렌더러가 처리합니다.

## HTML 문서

관리자 에디터에서 HTML 문서를 저장할 수 있습니다. 저장 시 허용된 태그만 남기며 `script`, `iframe`, 이벤트 속성, `javascript:` URL은 제거됩니다.

허용 범위가 더 필요하면 `lib/HtmlSanitizer.php`의 허용 태그와 속성을 검토한 뒤 추가하세요.
