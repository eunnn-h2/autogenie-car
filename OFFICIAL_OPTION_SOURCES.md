# 차량 정보 옵션 데이터 출처

- 정리일: 2026-09-15
- 적용 차량: 현재 프로젝트의 131개 차량
- 데이터 파일: `data/vehicle-options-official.json`
- 표시 방식: DB의 `car_vehicle_options`에 데이터가 있으면 DB가 우선이며, 비어 있는 차량/트림에만 공식 사이트 기반 JSON 데이터가 표시됩니다.

## 작성 기준

제조사 국내 공식 홈페이지의 모델 페이지, 가격표, 카탈로그, 공식 보도자료에서 공개한 기능명과 사양을 기준으로 차량 정보 화면용 설명을 짧게 재작성했습니다.
제조사 웹사이트의 문장을 장문으로 복사하지 않고, 기능의 의미를 이해하기 쉬운 문장으로 요약했습니다.

차량의 연식, 세부 트림, 생산 시점, 선택 사양에 따라 실제 적용 사양은 달라질 수 있으므로 차량 정보 화면에도 이 안내가 표시됩니다.

## 공식 사이트

- 현대자동차: https://www.hyundai.com/kr/ko/e/all-vehicles
- 제네시스: https://www.genesis.com/kr/ko/main.html
- 기아: https://www.kia.com/kr/vehicles
- BMW Korea: https://www.bmw.co.kr/ko/all-models.html
- Mercedes-Benz Korea: https://www.mercedes-benz.co.kr/
- Audi Korea: https://www.audi.co.kr/ko/models/
- Volvo Cars Korea: https://www.volvocars.com/kr/cars/
- Land Rover Korea: https://www.landroverkorea.co.kr/
- KGM: https://www.kg-mobility.com/kr/
- Renault Korea: https://www.renault.co.kr/
- Chevrolet Korea: https://www.chevrolet.co.kr/
- Tesla Korea: https://www.tesla.com/ko_kr
- Volkswagen Korea: https://www.volkswagen.co.kr/ko/models.html

## 확인에 사용한 대표 페이지

- 현대 아반떼: https://www.hyundai.com/kr/ko/vehicles/avante
- 현대 그랜저: https://www.hyundai.com/kr/ko/e/vehicles/grandeur/intro
- 현대 포터 II Electric: https://www.hyundai.com/kr/ko/e/vehicles/porter2-electric/intro
- 기아 EV3: https://www.kia.com/kr/vehicles/ev3/features
- 기아 EV5: https://www.kia.com/kr/vehicles/ev5/features
- 기아 PV5 카고: https://www.kia.com/kr/vehicles/pv5-cargo/features
- BMW X5: https://www.bmw.co.kr/ko/all-models/x-series/suv/bmw-x5.html
- Audi A6: https://www.audi.co.kr/ko/models/a6/a6-sedan-2026/
- Audi Q6 e-tron: https://www.audi.co.kr/ko/models/q6-e-tron/q6-suv-e-tron-2025/
- Volvo XC60: https://www.volvocars.com/kr/cars/xc60/features/
- Chevrolet 트랙스 크로스오버: https://www.chevrolet.co.kr/cuvs/trax-crossover
- Chevrolet 트레일블레이저: https://www.chevrolet.co.kr/suvs/trailblazer
- Volkswagen ID.4: https://www.volkswagen.co.kr/ko/models/id4.html
- Tesla Model 3: https://www.tesla.com/ko_kr/support/meet-your-tesla/model-3

## 이미지 처리

현재 프로젝트에 이미 등록되어 있는 해당 차량 대표 이미지를 옵션 카드 이미지로 사용하도록 구성했습니다.
기존 `images/options/`에 전용 옵션 이미지가 있는 DB 데이터는 DB 데이터가 우선하므로 그대로 유지됩니다.

## 옵션 이미지 처리 기준

- 옵션 카드에는 차량 외관 대표 이미지를 사용하지 않습니다.
- `images/options/`에 해당 옵션을 직접 보여주는 전용 이미지가 있는 경우에만 연결합니다.
- 전용 이미지가 없는 옵션은 이미지 영역을 표시하지 않습니다.
- 현재 프로젝트에 이미 존재하는 BMW 옵션 이미지(커브드 디스플레이, 드라이빙/파킹 어시스턴트, 디지털 키, 하이빔, 무선 충전 트레이)는 그대로 연결합니다.
