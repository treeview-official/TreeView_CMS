# Deployment Checklist

## 업로드 전

- `config.php`에 실제 DB 비밀번호가 들어간 상태로 GitHub에 커밋하지 않았는지 확인
- 운영 서버용 `BASE_PATH` 확인
- `ADMIN_PASSWORD`를 긴 랜덤 문자열로 변경
- DB 백업 또는 신규 DB 생성 확인
- PHP 버전과 필수 확장 확인

## GitHub로 관리할 때

권장 방식은 GitHub에는 코드와 예시 설정만 올리고, 운영 서버의 실제 설정은 서버에서 별도로 관리하는 것입니다.

- 저장소에는 `config.example.php`를 유지합니다.
- `config.php`는 비밀번호가 없는 템플릿 상태로 두거나, 운영 서버에서만 수정합니다.
- 실제 운영 DB 비밀번호가 들어간 파일을 커밋했다면 비밀번호를 즉시 교체하세요.

## 압축 업로드로 배포할 때

1. 로컬에서 배포 파일을 압축합니다.
2. 서버에 업로드하고 압축을 풉니다.
3. 서버에서 `config.php`를 수정합니다.
4. `/install.php`를 실행합니다.
5. 설치 완료 후 `install.php`를 삭제하거나 접근 제한합니다.

압축 파일에는 `.git` 폴더를 포함하지 않는 것이 좋습니다.

## Apache 보안

기본 `.htaccess`는 다음 파일 접근을 차단합니다.

- `config.php`
- `*.sql`
- `README.md`
- `.git`

호스팅에서 `.htaccess`를 허용하지 않으면 서버 설정에서 직접 차단해야 합니다.

## Nginx 보안 예시

```nginx
location ~ /\.(git|svn) {
    deny all;
}

location ~* \.(sql|md)$ {
    deny all;
}

location = /config.php {
    deny all;
}

location = /install.php {
    deny all;
}
```

설치 전에는 `install.php` 차단을 잠시 해제하고, 설치 후 다시 차단하세요.

## 배포 후 점검

- 메인 페이지 접속
- 관리자 로그인
- 문서 등록, 수정, 삭제
- 카테고리 필터
- Contact 문의 저장
- sitemap 접근
- 모바일 화면 확인
- `config.php`, `schema.sql`, `.git` URL 직접 접근 시 차단 확인
