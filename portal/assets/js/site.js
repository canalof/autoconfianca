/* Comportamentos comuns aos templates Softmax — sem dependencias externas */
(function () {
  "use strict";
  var d = document;

  /* menu mobile */
  document.querySelectorAll("[data-menu]").forEach(function (btn) {
    var id = btn.getAttribute("data-menu");
    var panel = d.getElementById(id);
    if (!panel) return;
    btn.addEventListener("click", function () {
      var open = panel.classList.toggle("is-open");
      btn.setAttribute("aria-expanded", open ? "true" : "false");
      d.body.classList.toggle("menu-open", open);
    });
    panel.querySelectorAll("a").forEach(function (a) {
      a.addEventListener("click", function () {
        panel.classList.remove("is-open");
        btn.setAttribute("aria-expanded", "false");
        d.body.classList.remove("menu-open");
      });
    });
  });

  /* header ao rolar */
  var head = d.querySelector("[data-head]");
  if (head) {
    var onScroll = function () {
      head.classList.toggle("is-stuck", window.scrollY > 12);
    };
    window.addEventListener("scroll", onScroll, { passive: true });
    onScroll();
  }

  /* reveal */
  var items = d.querySelectorAll(".rv");
  if ("IntersectionObserver" in window && items.length) {
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (e) {
        if (e.isIntersecting) { e.target.classList.add("in"); io.unobserve(e.target); }
      });
    }, { rootMargin: "0px 0px -8% 0px", threshold: 0.08 });
    items.forEach(function (el, i) {
      el.style.transitionDelay = (i % 4) * 70 + "ms";
      io.observe(el);
    });
  } else {
    items.forEach(function (el) { el.classList.add("in"); });
  }

  /* contadores */
  var nums = d.querySelectorAll("[data-count]");
  if (nums.length && "IntersectionObserver" in window) {
    var io2 = new IntersectionObserver(function (entries) {
      entries.forEach(function (e) {
        if (!e.isIntersecting) return;
        var el = e.target, target = parseFloat(el.getAttribute("data-count"));
        var dec = (el.getAttribute("data-dec") || "0") === "1";
        var dur = 1100, t0 = performance.now();
        function step(t) {
          var p = Math.min(1, (t - t0) / dur);
          var v = target * (1 - Math.pow(1 - p, 3));
          el.textContent = dec ? v.toFixed(1).replace(".", ",") : (target >= 10000 ? Math.round(v).toLocaleString("pt-BR") : String(Math.round(v)));
          if (p < 1) requestAnimationFrame(step);
        }
        requestAnimationFrame(step);
        io2.unobserve(el);
      });
    }, { threshold: 0.5 });
    nums.forEach(function (n) { io2.observe(n); });
  }

  /* ancora: reposiciona depois que fontes e imagens carregam */
  function irParaAncora() {
    if (!location.hash) return;
    var alvo = document.querySelector(location.hash);
    if (alvo) alvo.scrollIntoView({ block: "start" });
  }
  window.addEventListener("load", irParaAncora);
  if (document.fonts && document.fonts.ready) document.fonts.ready.then(irParaAncora);

  /* ano no rodape */
  d.querySelectorAll("[data-year]").forEach(function (el) {
    el.textContent = new Date().getFullYear();
  });

  /* mascara simples de telefone */
  d.querySelectorAll('input[data-phone]').forEach(function (inp) {
    inp.addEventListener("input", function () {
      var v = inp.value.replace(/\D/g, "").slice(0, 11);
      if (v.length > 6) v = "(" + v.slice(0, 2) + ") " + v.slice(2, 7) + "-" + v.slice(7);
      else if (v.length > 2) v = "(" + v.slice(0, 2) + ") " + v.slice(2);
      inp.value = v;
    });
  });

  /* formularios demo */
  d.querySelectorAll("form[data-demo]").forEach(function (f) {
    f.addEventListener("submit", function (ev) {
      ev.preventDefault();
      var ok = f.querySelector("[data-ok]");
      if (ok) { ok.hidden = false; f.reset(); }
    });
  });

  /* formularios -> WhatsApp da oficina */
  d.querySelectorAll("form[data-wa]").forEach(function (f) {
    f.addEventListener("submit", function (ev) {
      ev.preventDefault();
      var val = function (n) {
        var el = f.querySelector('[name="' + n + '"]');
        return el ? el.value.trim() : "";
      };
      var titulos = { nome: "Nome", telefone: "WhatsApp", email: "E-mail", veiculo: "Veículo", placa: "Placa", km: "KM", servico: "Serviço", periodo: "Melhor período", mensagem: "Detalhes" };
      var linhas = ["Olá! Vim pelo site da AutoConfiança."];
      Object.keys(titulos).forEach(function (k) {
        var v = val(k);
        if (v) linhas.push(titulos[k] + ": " + v);
      });
      var numero = f.getAttribute("data-wa");
      window.open("https://wa.me/" + numero + "?text=" + encodeURIComponent(linhas.join("\n")), "_blank", "noopener");
      var ok = f.querySelector("[data-ok]");
      if (ok) ok.hidden = false;
    });
  });
})();
