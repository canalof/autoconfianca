(function () {
  const O = window.OFICINA;
  if (!O) return;
  const abrirWhats = (msg) => window.open(`https://wa.me/${O.whatsapp}?text=${encodeURIComponent(msg)}`, "_blank", "noopener");
  const nomeServico = (slug) => (O.servicos.find((s) => s.slug === slug) || {}).nome || "";
  const km = (n) => Number(n).toLocaleString("pt-BR");

  // Ordem de serviço: sintoma -> explicação -> WhatsApp
  const os = document.getElementById("diagnostico");
  if (os) {
    const saida = document.getElementById("os-diagnostico");
    os.addEventListener("change", (e) => {
      if (e.target.name !== "sintoma") return;
      const s = O.sintomas.find((x) => x.id === e.target.value);
      saida.innerHTML = "";
      const p = document.createElement("span");
      p.textContent = s.explica + " ";
      const b = document.createElement("strong");
      b.textContent = "Serviço provável: " + nomeServico(s.servico) + ".";
      saida.append(p, b);
      saida.classList.add("preenchido");
    });
    os.addEventListener("submit", (e) => {
      e.preventDefault();
      const d = new FormData(os);
      const s = O.sintomas.find((x) => x.id === d.get("sintoma"));
      abrirWhats(`Olá! Vim pelo site.\nCarro: ${d.get("carro")}\nO que está acontecendo: ${s.texto}\nQueria um orçamento de ${nomeServico(s.servico).toLowerCase()}.`);
    });
  }

  // Agendamento
  const ag = document.getElementById("agendamento");
  if (ag) {
    const dia = ag.querySelector('input[name="dia"]');
    const hoje = new Date();
    dia.min = new Date(hoje.getTime() - hoje.getTimezoneOffset() * 60000).toISOString().slice(0, 10);
    ag.addEventListener("submit", (e) => {
      e.preventDefault();
      const d = new FormData(ag);
      const data = new Date(d.get("dia") + "T12:00:00").toLocaleDateString("pt-BR", { weekday: "long", day: "2-digit", month: "2-digit" });
      abrirWhats(`Olá! Quero agendar um serviço.\nNome: ${d.get("nome")}\nCarro: ${d.get("carro")}\nServiço: ${d.get("servico")}\nDia: ${data}, período da ${d.get("periodo")}`);
    });
  }

  // Calculadora da troca de óleo
  const ho = document.getElementById("hodometro");
  if (ho) {
    const res = document.getElementById("hodometro-resultado");
    ho.addEventListener("submit", (e) => {
      e.preventDefault();
      const d = new FormData(ho);
      const atual = +d.get("atual"), ultima = +d.get("ultima"), intervalo = +d.get("intervalo");
      res.hidden = false;
      res.innerHTML = "";
      const linha = document.createElement("p");
      if (ultima > atual) {
        linha.textContent = "A km da última troca está maior que a km atual. Confira os números.";
        res.append(linha);
        return;
      }
      const proxima = ultima + intervalo;
      const falta = proxima - atual;
      res.dataset.estado = falta <= 0 ? "vencido" : falta <= 1000 ? "perto" : "ok";
      linha.textContent = falta <= 0
        ? `A troca venceu há ${km(-falta)} km. O ideal era em ${km(proxima)} km.`
        : `Faltam ${km(falta)} km. Próxima troca em ${km(proxima)} km.`;
      const barra = document.createElement("div");
      barra.className = "hodometro-barra";
      barra.style.setProperty("--uso", Math.min(100, Math.max(0, ((atual - ultima) / intervalo) * 100)) + "%");
      barra.setAttribute("aria-hidden", "true");
      res.append(barra, linha);
      if (falta <= 1000) {
        const btn = document.createElement("button");
        btn.type = "button";
        btn.className = "botao botao-sinal";
        btn.textContent = "Agendar a troca de óleo";
        btn.addEventListener("click", () => abrirWhats(`Olá! Meu carro está com ${km(atual)} km e preciso trocar o óleo. Quero agendar.`));
        res.append(btn);
      }
    });
  }
})();
