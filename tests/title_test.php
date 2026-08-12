<?php
declare(strict_types=1);

use Health\Notion\Title;

T::group('제목 파싱 (문서 §2.5)');

$a = Title::parse('(1-10)백송이님 26.07.23(목)20:00');
T::is($a['code'], '1-10', '형식1 회차 코드');
T::is($a['date'], '26.07.23', '형식1 날짜');
T::is($a['weekday'], '목', '형식1 요일');
T::is($a['time'], '20:00', '형식1 시각');

$b = Title::parse('(OT)백송이 회원님/26.06.10(수) [21:30]');
T::is($b['code'], 'OT', '형식2 회차 코드');
T::is($b['date'], '26.06.10', '형식2 날짜');
T::is($b['weekday'], '수', '형식2 요일');
T::is($b['time'], '21:30', '형식2 시각');

$c = Title::parse('제목만 있고 날짜가 없다');
T::is($c['code'], null, '괄호가 없으면 코드는 null');
T::is($c['date'], null, '날짜가 없으면 null');

// 한 자리 시각도 두 자리로 맞춘다.
T::is(Title::parse('26.06.10(수) 9:05')['time'], '09:05', '한 자리 시각을 0으로 채운다');

T::group('날짜 정렬용 변환');
T::is(Title::isoDate('26.07.23'), '2026-07-23', 'YY.MM.DD → YYYY-MM-DD');
T::is(Title::isoDate(null), null, 'null 은 그대로 null');
T::is(Title::isoDate('이상한 값'), null, '못 읽으면 null');

T::group('운동 토글 제목');

$x = Title::exercise("[1] 스쿼트+런지\n→ 8회 3세트");
T::is($x['name'], '스쿼트+런지', '[n] 접두사를 뗀 첫 줄이 이름');
T::is($x['meta'], '→ 8회 3세트', '나머지 줄이 세트 메모');

$y = Title::exercise('플랭크');
T::is($y['name'], '플랭크', '한 줄이면 이름만');
T::is($y['meta'], null, '메모가 없으면 null');

$z = Title::exercise("[12]  레그 프레스  \n→ 15회\n→ 3세트");
T::is($z['name'], '레그 프레스', '두 자리 번호도 뗀다');
T::is($z['meta'], "→ 15회\n→ 3세트", '여러 줄 메모를 모두 담는다');
