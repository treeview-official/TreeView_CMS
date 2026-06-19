# TreeView CMS

> **Obsidian 스타일의 웹 기반 Knowledge Management System (KMS)**

TreeView CMS는 **PHP + MySQL** 기반으로 제작된 웹 지식 관리 시스템입니다.

문서를 작성하고, 문서 간 연결(`[[링크]]`), 태그(`#태그`), 백링크, 그래프 뷰를 통해 지식을 체계적으로 관리할 수 있습니다.

개인 위키, 개발 문서, 회사 내부 위키, 기술 아카이브 등 다양한 용도로 활용할 수 있습니다.

---

# ✨ 주요 기능

- 📝 웹에서 문서 작성 및 수정
- 📂 카테고리 기반 문서 관리
- 🔗 `[[문서명]]` 내부 링크 지원
- 🏷️ `#태그` 자동 인식
- ↔️ 백링크(Backlinks)
- 🔍 실시간 문서 검색
- 🌐 그래프(Graph View)
- 📚 MySQL 기반 문서 저장
- ⚡ 빠른 문서 탐색
- 📱 반응형 UI 지원

---

# 📸 주요 화면

- 문서 목록
- 문서 보기
- 문서 편집
- 카테고리 탐색
- 태그 목록
- 백링크
- 그래프 뷰
- 검색

---

# 🚀 설치

## 1. 프로젝트 업로드

웹 서버에 TreeView CMS 파일을 업로드합니다.

```
/treeview
    /admin
    /assets
    /install.php
    /config.php
    /index.php
```

---

## 2. 데이터베이스 설정

`config.php`에서 데이터베이스 정보를 입력합니다.

```php
$db_host = "localhost";
$db_user = "root";
$db_pass = "";
$db_name = "treeview";
```

---

## 3. 설치

브라우저에서 아래 주소를 실행합니다.

```
https://your-domain.com/install.php
```

데이터베이스 테이블이 자동으로 생성됩니다.

---

## 4. 설치 완료

설치가 완료되면 보안을 위해

```
install.php
```

파일을 삭제하거나 이름을 변경하는 것을 권장합니다.

---

# ✍️ 문서 작성

관리자 로그인 후 **`+`\*\*** 버튼\*\*을 눌러 새로운 문서를 작성할 수 있습니다.

예시

```txt
카테고리: 개발/PHP

TreeView CMS는 PHP와 MySQL 기반으로 제작된 웹 지식 관리 시스템입니다.

관련 기술
PHP
MySQL
JavaScript

[[웹개발]]
[[데이터베이스]]
[[Markdown]]

#PHP
#CMS
#KnowledgeBase
```

---

# 📂 카테고리

카테고리는 `/` 구분자를 이용하여 계층 구조를 만들 수 있습니다.

예시

```
개발/PHP
개발/Python
개발/JavaScript

인프라/Docker
인프라/Nginx

AI/LLM
AI/Prompt

정치/정당
정치/인물
```

카테고리별 문서 목록을 제공하며, 상위 카테고리에서 하위 문서를 함께 탐색할 수 있습니다.

---

# 🔗 내부 링크

문서 내에서

```txt
[[PHP]]
[[MySQL]]
[[Docker]]
```

처럼 작성하면 자동으로 내부 링크가 생성됩니다.

존재하지 않는 문서는 새 문서 생성 대상으로 표시할 수 있습니다.

---

# 🏷️ 태그

문서 내에서

```txt
#PHP
#Backend
#Database
```

처럼 작성하면 태그가 자동 등록됩니다.

태그 페이지에서 동일한 태그를 사용하는 문서를 모아볼 수 있습니다.

---

# ↔️ 백링크

다른 문서에서 현재 문서를 참조하면 자동으로 백링크가 생성됩니다.

예시

```
PHP ← Laravel
PHP ← WordPress
PHP ← TreeView CMS
```

문서 간 관계를 쉽게 파악할 수 있습니다.

---

# 🌐 그래프 뷰

모든 문서는 노드(Node) 형태로 연결되어 시각화됩니다.

```
PHP
 ├── Laravel
 ├── WordPress
 └── TreeView CMS

MySQL
 ├── MariaDB
 └── Database
```

문서 간 연결 관계를 직관적으로 탐색할 수 있습니다.

---

# 🔍 검색

- 제목 검색
- 내용 검색
- 태그 검색
- 카테고리 검색

빠르게 원하는 문서를 찾을 수 있습니다.

---

# 💡 활용 사례

- 개발 위키
- 회사 내부 문서
- API 문서
- 기술 블로그
- PKM(Personal Knowledge Management)
- Obsidian 대체 웹 서비스
- 프로젝트 문서
- 학습 노트
- 연구 자료 관리

---

# 🛠️ 기술 스택

- PHP
- MySQL
- HTML5
- CSS3
- JavaScript
- AJAX
- SVG
- Markdown Parser

---

# 📦 요구 사항

- PHP 8.1 이상
- MySQL 5.7 이상 또는 MariaDB 10.x 이상
- Apache 또는 Nginx
- mod_rewrite(선택)

---

# 📌 로드맵

- [x] 문서 작성
- [x] 내부 링크
- [x] 태그
- [x] 백링크
- [x] 검색
- [x] 그래프 뷰
- [ ] Markdown 확장
- [ ] 파일 첨부
- [ ] 버전 관리
- [ ] 협업 기능
- [ ] REST API
- [ ] 플러그인 시스템
- [x] 다국어 지원

---

# ❤️ TreeView CMS

**TreeView CMS**는 문서를 저장하는 것을 넘어, **문서 간 연결과 관계를 시각화하여 지식을 성장시키는 웹 기반 Knowledge Management System**입니다.

복잡한 정보를 하나의 트리(Tree)처럼 연결하고 탐색할 수 있도록 설계되었습니다.
