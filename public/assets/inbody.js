// 인바디 앱 열기.
//
// iOS 는 "앱이 깔려 있나"를 물어볼 방법을 주지 않는다. 그래서 흔히 쓰는 방법으로 한다 —
// 커스텀 스킴으로 넘겨보고, 잠시 뒤에도 이 화면이 그대로 살아 있으면 앱이 없다고 보고
// App Store 로 보낸다. 앱이 열리면 화면이 뒤로 숨겨져서(visibilitychange) 타이머를 취소한다.
//
// JavaScript 가 꺼져 있어도 화면의 버튼 두 개로 직접 갈 수 있다.
(function () {
  var el = document.getElementById('inbody');
  if (!el) return;

  var scheme = el.getAttribute('data-scheme');
  var store  = el.getAttribute('data-store');
  var wait   = parseInt(el.getAttribute('data-wait') || '1200', 10);
  var left   = false;

  function bail() { left = true; }
  document.addEventListener('visibilitychange', function () {
    if (document.hidden) bail();
  });
  window.addEventListener('pagehide', bail);
  window.addEventListener('blur', bail);

  var timer = setTimeout(function () {
    if (!left && !document.hidden) location.replace(store);
  }, wait);

  window.addEventListener('pagehide', function () { clearTimeout(timer); });

  // 스킴으로 넘긴다. 앱이 없으면 아무 일도 일어나지 않고 위 타이머가 App Store 로 보낸다.
  location.href = scheme;
})();
