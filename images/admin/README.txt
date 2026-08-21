오토지니 관리자 - 계정/권한 관리 적용본

권한
1. SUPER_ADMIN
   - 차량/색상/트림/가격 조회·등록·수정·삭제
   - 엑셀 일괄등록
   - 다른 관리자 추가/수정/비밀번호변경/삭제
2. ADMIN
   - 차량/색상/트림/가격 조회·등록·수정·삭제
   - 엑셀 일괄등록
   - 관리자 계정 관리는 불가
3. VIEWER
   - 차량 데이터 조회만 가능
   - 등록/수정/삭제/엑셀등록 불가

[이미 이전 로그인 버전을 설치했다면]
현재 admins 테이블에 role 컬럼이 없으므로:
1. phpMyAdmin → autogenie → SQL
2. admins_role_migration.sql의 내용을 실행
3. 맨 아래 username을 현재 쓰는 관리자 아이디로 꼭 변경:
   UPDATE admins SET role='SUPER_ADMIN' WHERE username='현재아이디';

[새로 처음 설치한다면]
admins_table.sql 실행 후 setup_admin.php에서 최초 계정을 만들면
자동으로 SUPER_ADMIN으로 생성됩니다.

[파일 설치]
압축 안의 PHP 파일들을 기존 autogenie/admin/ 폴더에 덮어씁니다.

admin/
├─ index.php
├─ auth.php
├─ login.php
├─ logout.php
├─ setup_admin.php
├─ admins.php
├─ admins_table.sql
└─ admins_role_migration.sql

[관리자 추가]
SUPER_ADMIN으로 로그인 후 관리자 화면 좌측:
관리자 → 관리자 계정 관리

또는:
http://localhost/autogenie/admin/admins.php

관리자 목록에서:
- 새 관리자 추가
- 이름/권한 변경
- 활성/비활성
- 비밀번호 변경
- 계정 삭제

안전장치:
- 현재 로그인한 본인 계정 삭제 불가
- 본인 계정 비활성화 불가
- 마지막 활성 SUPER_ADMIN 삭제 불가
- 마지막 활성 SUPER_ADMIN의 권한 하향/비활성화 불가

[견적 저장/조회 테스트 추가]
1. phpMyAdmin → autogenie DB → SQL
2. 프로젝트 루트의 estimates_table.sql 내용을 실행
3. 브라우저에서 http://localhost/autogenie/db-test.html 접속
4. 차량/트림/색상/이용조건 + 고객정보 선택 후 저장
5. 관리자 → 견적 신청 관리에서 목록/상세 확인

추가 파일
- estimates_table.sql
- db-test.html
- api/save-estimate.php
- admin/estimates.php
- admin/estimate-detail.php

[간편견적 저장 추가]
1. phpMyAdmin → autogenie DB → SQL
2. 프로젝트 루트의 quick_estimates_table.sql 내용을 한 번 실행
3. 사용자 화면 하단의 '간편견적'에서 신청
4. 관리자 → 견적 신청 관리에서 '간편견적' 배지로 함께 확인
5. 상세 화면에서 관심 차종 / 희망 월 예산 / 이용 방식 확인 가능

추가 파일
- quick_estimates_table.sql
- api/save-quick-estimate.php
