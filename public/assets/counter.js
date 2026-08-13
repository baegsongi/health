// 오늘의 운동 — 카운터.
//
// 손가락이 닿는 순간 화면이 바뀌어야 한다. 그래서 전부 "화면 먼저, 서버는 뒤에" 로 한다.
// 이 NAS 는 쓰기 한 번에 0.5~2초가 걸린다 — 답을 기다렸다 그리면 아무것도 안 눌린 것처럼 느껴진다.
//
//   -/+      화면만 고치고, 손이 멈추면(450ms) "지금 몇 회"를 한 번만 보낸다
//   +1 세트  목록에 먼저 한 줄 붙이고 보낸다. 답이 오면 서버 것으로 갈아끼운다
//   실패하면 마지막으로 서버가 준 모습으로 되돌리고 까닭을 알려준다
//
// 화면은 어디로도 넘어가지 않는다.
(function () {
  var root = document.getElementById('counter');
  if (!root) return;

  var base = root.getAttribute('data-base');
  var csrf = root.getAttribute('data-csrf');
  var reps = parseInt(root.getAttribute('data-reps') || '0', 10);

  var $ = function (id) { return document.getElementById(id); };

  /** 마지막으로 서버가 알려 준 모습. 실패하면 여기로 되돌린다. */
  var state = JSON.parse(root.getAttribute('data-state') || '{"sets":[],"totalSets":0,"totalReps":0,"totalSecs":0}');

  /* 서버에 알리기 ------------------------------------------------ */

  function post(path, body) {
    var form = new URLSearchParams();
    form.set('_csrf', csrf);
    for (var k in body) if (body[k] !== null && body[k] !== undefined) form.set(k, body[k]);

    return fetch(base + path, {
      method: 'POST',
      headers: { 'Accept': 'application/json', 'Content-Type': 'application/x-www-form-urlencoded' },
      body: form.toString(),
      credentials: 'same-origin'
    }).then(function (r) {
      if (!r.ok) throw new Error('HTTP ' + r.status);
      return r.json();
    });
  }

  var busy = 0;
  function mark(on) {
    busy += on ? 1 : -1;
    root.classList.toggle('is-busy', busy > 0);
  }

  /** 보내고, 답이 오면 그 모습으로 그린다. 실패하면 마지막 성한 모습으로 되돌린다. */
  function send(path, body) {
    mark(true);
    return post(path, body).then(function (fresh) {
      if (fresh && fresh.sets) { state = fresh; render(state); }
    })['catch'](function (e) {
      render(state);
      note('저장하지 못했습니다 — ' + e.message);
    }).then(function () { mark(false); });
  }

  /* -/+ 는 모았다가 한 번에 --------------------------------------- */

  var syncTimer = null;
  function setReps(n) {
    reps = Math.max(0, n);
    $('rep-n').textContent = reps;
    clearTimeout(syncTimer);
    syncTimer = setTimeout(function () { post('/rep', { reps: reps })['catch'](function () {}); }, 450);
  }

  /* 그리기 -------------------------------------------------------- */

  function secsText(s) {
    s = Math.round(s);
    if (s < 60) return s + '초';
    var m = Math.floor(s / 60);
    if (m < 60) return (s % 60 === 0) ? m + '분' : m + '분 ' + (s % 60) + '초';
    return Math.floor(m / 60) + '시간' + (m % 60 === 0 ? '' : ' ' + (m % 60) + '분');
  }

  function kg(w) { return (Math.round(w * 10) / 10).toString(); }

  function row(s) {
    var li = document.createElement('li');
    li.className = 'setrow' + (s.pending ? ' setrow-pending' : '');

    var no = document.createElement('span');
    no.className = 'setno';
    no.textContent = s.set_no + '세트';

    var val = document.createElement('span');
    val.className = 'setval';
    val.textContent = (s.secs !== null && s.secs !== undefined)
      ? secsText(s.secs)
      : s.reps + '회' + (s.weight !== null && s.weight !== undefined ? ' · ' + kg(s.weight) + 'kg' : '');

    li.appendChild(no);
    li.appendChild(val);

    if (!s.pending) {
      var del = document.createElement('button');
      del.className = 'setdel';
      del.type = 'button';
      del.setAttribute('data-del', s.id);
      del.setAttribute('aria-label', '이 세트 지우기');
      del.textContent = '✕';
      li.appendChild(del);
    }
    return li;
  }

  function render(s) {
    var list = $('setlist');
    list.textContent = '';
    s.sets.forEach(function (x) { list.appendChild(row(x)); });

    var bits = [];
    if (s.totalSecs > 0) bits.push('<b>' + secsText(s.totalSecs) + '</b>');
    bits.push('<b>' + s.totalSets + '</b>세트');
    if (s.totalReps > 0) bits.push('<b>' + s.totalReps + '</b>회');
    $('totals').innerHTML = bits.join(' · ');
  }

  /** 화면에만 먼저 반영한 모습. 서버 답이 오면 덮인다. */
  function optimistic(change) {
    var copy = JSON.parse(JSON.stringify(state));
    change(copy);
    copy.totalSets = copy.sets.length;
    copy.totalReps = copy.sets.reduce(function (a, x) { return a + (x.reps || 0); }, 0);
    copy.totalSecs = copy.sets.reduce(function (a, x) { return a + (x.secs || 0); }, 0);
    render(copy);
  }

  var noteTimer = null;
  function note(text) {
    var el = $('cnote');
    if (!el) {
      el = document.createElement('p');
      el.id = 'cnote';
      el.className = 'error';
      root.appendChild(el);
    }
    el.textContent = text;
    clearTimeout(noteTimer);
    noteTimer = setTimeout(function () { if (el.parentNode) el.parentNode.removeChild(el); }, 4000);
  }

  function nextNo() {
    return state.sets.reduce(function (m, x) { return Math.max(m, x.set_no); }, 0) + 1;
  }

  /* 버튼 ---------------------------------------------------------- */

  $('rep-plus').addEventListener('click', function () { setReps(reps + 1); });
  $('rep-minus').addEventListener('click', function () { setReps(reps - 1); });

  $('weight').addEventListener('change', function () {
    post('/weight', { weight: this.value.trim() })['catch'](function () {});
  });

  $('commit').addEventListener('click', function () {
    if (reps <= 0) { note('먼저 횟수를 세어 주세요.'); return; }
    clearTimeout(syncTimer);

    var w = $('weight').value.trim();
    optimistic(function (s) {
      s.sets.push({ set_no: nextNo(), reps: reps, weight: w === '' ? null : parseFloat(w),
                    secs: null, pending: true });
    });
    send('/set', { reps: reps, weight: w });
  });

  $('setlist').addEventListener('click', function (ev) {
    var id = ev.target.getAttribute && ev.target.getAttribute('data-del');
    if (!id) return;
    optimistic(function (s) {
      s.sets = s.sets.filter(function (x) { return String(x.id) !== String(id); });
    });
    send('/delete', { set_id: id });
  });

  /* 탭 ------------------------------------------------------------ */

  Array.prototype.forEach.call(root.querySelectorAll('.seg'), function (btn) {
    btn.addEventListener('click', function () {
      var want = btn.getAttribute('data-pane');
      Array.prototype.forEach.call(root.querySelectorAll('.seg'), function (b) {
        b.classList.toggle('seg-on', b === btn);
      });
      Array.prototype.forEach.call(root.querySelectorAll('.pane'), function (p) {
        p.classList.toggle('pane-off', p.getAttribute('data-pane') !== want);
      });
      try { history.replaceState(null, '', '?tab=' + want); } catch (e) {}
    });
  });

  /* 스톱워치 ------------------------------------------------------ */

  var watch  = $('watch');
  var toggle = $('watch-toggle');
  var save   = $('watch-save');
  var acc    = 0;          // 멈춰 있는 동안 쌓인 밀리초
  var from   = 0;          // 이번에 돈 시작 시각
  var tick   = null;

  function elapsed() { return acc + (from ? Date.now() - from : 0); }

  function face(ms) {
    var t   = Math.floor(ms / 10);          // 1/100초
    var pad = function (n) { return (n < 10 ? '0' : '') + n; };
    return pad(Math.floor(t / 6000)) + ':' + pad(Math.floor(t / 100) % 60) + '.' + pad(t % 100);
  }

  function paint() {
    watch.textContent = face(elapsed());
    tick = requestAnimationFrame(paint);
  }

  function idle() {
    toggle.textContent = '시작';
    toggle.classList.add('btn-primary');
    toggle.classList.remove('btn-stop');
  }

  function start() {
    from = Date.now();
    toggle.textContent = '정지';
    toggle.classList.remove('btn-primary');
    toggle.classList.add('btn-stop');
    paint();
  }

  function stop() {
    acc  = elapsed();
    from = 0;
    if (tick) cancelAnimationFrame(tick);
    tick = null;
    watch.textContent = face(acc);
    idle();
    save.disabled = acc < 1000;
  }

  toggle.addEventListener('click', function () { from ? stop() : start(); });

  $('watch-reset').addEventListener('click', function () {
    if (tick) cancelAnimationFrame(tick);
    tick = null; acc = 0; from = 0;
    watch.textContent = '00:00.00';
    idle();
    save.disabled = true;
  });

  save.addEventListener('click', function () {
    if (from) stop();
    if (acc < 1000) return;
    var secs = acc / 1000;

    optimistic(function (s) {
      s.sets.push({ set_no: nextNo(), reps: 0, weight: null, secs: secs, pending: true });
    });
    send('/time', { secs: secs.toFixed(2) });

    acc = 0;
    watch.textContent = '00:00.00';
    save.disabled = true;
  });

  // 화면을 잠갔다 돌아와도 시간이 맞아야 한다 — Date.now 로 재므로 그대로 이어진다.
  document.addEventListener('visibilitychange', function () {
    if (!document.hidden && from) watch.textContent = face(elapsed());
  });
})();
