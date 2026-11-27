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
  // Agendamento: envia para o sistema (/sistema/agendar). Se o sistema não responder,
  // abre o WhatsApp da oficina com a mensagem pronta, como antes.
  d.querySelectorAll("input[data-dia]").forEach(function (inp) {
    var amanha = new Date(); amanha.setDate(amanha.getDate() + 1);
    var max = new Date(); max.setDate(max.getDate() + 60);
    var iso = function (dt) { return new Date(dt.getTime() - dt.getTimezoneOffset() * 60000).toISOString().slice(0, 10); };
    inp.min = iso(amanha); inp.max = iso(max);
  });
  d.querySelectorAll("form[data-wa]").forEach(function (f) {
    var val = function (n) {
      var el = f.querySelector('[name="' + n + '"]');
      return el ? el.value.trim() : "";
    };
    var mostrar = function (sel, texto) {
      var ok = f.querySelector("[data-ok]"), erro = f.querySelector("[data-erro]");
      if (ok) ok.hidden = true;
      if (erro) erro.hidden = true;
      var el = f.querySelector(sel);
      if (el) { el.textContent = ""; if (typeof texto === "string") el.textContent = texto; else el.appendChild(texto); el.hidden = false; }
    };
    var abrirWhats = function (direto) {
      var titulos = { nome: "Nome", telefone: "WhatsApp", email: "E-mail", veiculo: "Veículo", placa: "Placa", km: "KM", servico: "Serviço", dia: "Dia", periodo: "Período", mensagem: "Detalhes" };
      var linhas = ["Olá! Vim pelo site da AutoConfiança e quero agendar."];
      Object.keys(titulos).forEach(function (k) {
        var v = val(k);
        if (k === "periodo") v = v === "tarde" ? "Tarde" : (v ? "Manhã" : "");
        if (k === "dia" && v) v = v.split("-").reverse().join("/");
        if (v) linhas.push(titulos[k] + ": " + v);
      });
      var link = "https://wa.me/" + f.getAttribute("data-wa") + "?text=" + encodeURIComponent(linhas.join("\n"));
      if (direto) {
        window.open(link, "_blank", "noopener");
        mostrar("[data-ok]", "Mensagem pronta no WhatsApp. Se a janela não abrir, chame no (19) 99482-7163.");
        return;
      }
      // Depois de uma espera o navegador bloqueia janela automática: mostra um link para tocar.
      var p = d.createElement("span");
      p.appendChild(d.createTextNode("Não conseguimos registrar o pedido agora. "));
      var a = d.createElement("a");
      a.href = link; a.target = "_blank"; a.rel = "noopener"; a.textContent = "Toque aqui para mandar pelo WhatsApp.";
      p.appendChild(a);
      mostrar("[data-erro]", p);
    };
    f.addEventListener("submit", function (ev) {
      ev.preventDefault();
      var url = f.getAttribute("data-agendar");
      if (!url || !window.fetch) { abrirWhats(true); return; }
      var dia = val("dia");
      if (dia) {
        var sem = new Date(dia + "T12:00:00").getDay();
        if (sem === 0 || (sem === 6 && val("periodo") === "tarde")) {
          mostrar("[data-erro]", "Atendemos de segunda a sexta e sábado de manhã. Escolha outro dia ou período.");
          return;
        }
      }
      var botao = f.querySelector('button[type="submit"]');
      if (botao) botao.setAttribute("aria-busy", "true");
      fetch(url, { method: "POST", body: new FormData(f), headers: { "Accept": "application/json" }, credentials: "same-origin" })
        .then(function (r) {
          return r.json().catch(function () { return {}; }).then(function (j) { return { status: r.status, j: j }; });
        })
        .then(function (res) {
          if (res.status === 200 && res.j.ok) {
            mostrar("[data-ok]", res.j.mensagem || "Pedido recebido!");
            if (!res.j.repetido) f.reset();
          } else if (res.status === 422) {
            var erros = res.j.errors ? Object.keys(res.j.errors).map(function (k) { return res.j.errors[k][0]; }) : [];
            mostrar("[data-erro]", erros[0] || res.j.message || "Confira os campos.");
          } else if (res.status === 429) {
            mostrar("[data-erro]", "Muitos pedidos seguidos. Chame a oficina no WhatsApp: (19) 99482-7163.");
          } else {
            abrirWhats(false);
          }
        })
        .catch(function () { abrirWhats(false); })
        .then(function () { if (botao) botao.removeAttribute("aria-busy"); });
    });
  });
})();
