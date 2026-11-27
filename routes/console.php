<?php

use App\Models\Categoria;
use App\Models\CategoriaServico;
use App\Models\Empresa;
use App\Models\Loja;
use App\Models\User;
use App\Support\Documento;
use App\Support\Perfil;
use App\Support\Tenant;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Str;

/*
 * Cria um cliente novo do sistema (empresa + loja matriz + usuário dono).
 * Uso: php artisan torque:empresa "Autocenter Silva" dono@autocentersilva.com.br --cnpj=11222333000181 --nome="José Silva"
 */
Artisan::command('torque:empresa {nome_fantasia} {email} {--cnpj=} {--nome=Responsável} {--senha=}', function () {
    $email = strtolower($this->argument('email'));
    if (User::where('email', $email)->exists()) {
        $this->error("Já existe usuário com o e-mail {$email}.");

        return 1;
    }
    $cnpj = Documento::normalizar($this->option('cnpj')) ?: null;
    if ($cnpj && ! Documento::cnpjValido($cnpj)) {
        $this->error('CNPJ inválido.');

        return 1;
    }
    $senha = $this->option('senha') ?: Str::password(12, symbols: false);

    DB::transaction(function () use ($cnpj, $email, $senha) {
        $empresa = Empresa::create([
            'nome_fantasia' => $this->argument('nome_fantasia'), 'cnpj' => $cnpj,
            'assinatura_status' => 'ativa',
        ]);
        Categoria::criarPadroes($empresa->id);
        CategoriaServico::criarPadroes($empresa->id);
        Tenant::com($empresa->id, function () use ($empresa, $email, $senha) {
            $loja = Loja::create(['nome' => 'Matriz', 'cnpj' => $empresa->cnpj]);
            User::create([
                'empresa_id' => $empresa->id, 'loja_id' => $loja->id, 'name' => $this->option('nome'),
                'email' => $email, 'perfil' => Perfil::Dono, 'ativo' => true, 'password' => $senha,
            ]);
        });
        $this->info("Empresa criada (id {$empresa->id}).");
    });

    $this->line("Login: {$email}");
    $this->line("Senha: {$senha}");
    $this->comment('Peça para trocar a senha no primeiro acesso (menu Trocar senha).');

    return 0;
})->purpose('Cria uma empresa cliente com o usuário dono');

/*
 * Atualiza notas fiscais que ainda estão processando na prefeitura/SEFAZ.
 */
Artisan::command('torque:notas', function (\App\Services\Fiscal\NotasFiscais $servico) {
    $n = 0;
    foreach (Empresa::where('ativo', true)->pluck('id') as $empresaId) {
        Tenant::com($empresaId, function () use ($servico, &$n) {
            foreach (\App\Models\NotaFiscal::where('status', 'processando')->where('created_at', '>', now()->subDays(3))->get() as $nota) {
                $servico->atualizar($nota);
                $n++;
            }
        });
    }
    $this->info("{$n} nota(s) consultada(s).");
})->purpose('Consulta as notas fiscais em processamento');

/*
 * Envia lembretes de revisão e confirmações de agenda pelo WhatsApp automático (Evolution API).
 * Só age nas empresas com instância configurada.
 */
Artisan::command('torque:lembretes', function (\App\Services\WhatsApp\WhatsApp $whatsapp) {
    $enviados = 0;
    foreach (Empresa::where('ativo', true)->whereNotNull('whatsapp_instancia')->get() as $empresa) {
        if (! $whatsapp->disponivel($empresa)) {
            continue;
        }
        Tenant::com($empresa->id, function () use ($empresa, $whatsapp, &$enviados) {
            $enviados += app(\App\Services\WhatsApp\EnvioLembretes::class)->executar($empresa);
            $amanha = \App\Models\Agendamento::with('cliente')->whereBetween('inicio', [today()->addDay(), today()->addDay()->endOfDay()])
                ->whereIn('status', ['agendado', 'confirmado'])->whereNull('lembrete_enviado_em')->get();
            foreach ($amanha as $a) {
                if ($a->cliente && ! $a->cliente->aceita_whatsapp) {
                    continue; // cliente pediu para não receber
                }
                $txt = 'Olá, '.explode(' ', trim($a->nome))[0].'! Lembrando do seu horário amanhã às '.$a->inicio->format('H:i')
                    .' na '.$empresa->nome_fantasia.' ('.$a->servico.'). Pode confirmar respondendo SIM. Se precisar remarcar, é só responder.';
                if ($whatsapp->enviar($empresa, $a->telefone ?: ($a->cliente?->whatsapp ?: $a->cliente?->telefone), $txt, 'agenda_vespera', $a->id)) {
                    $a->update(['lembrete_enviado_em' => now()]);
                    $enviados++;
                }
            }
            // Nova tentativa das que falharam nas últimas 24 h (até 3 tentativas)
            $enviados += $whatsapp->reenviarFalhas($empresa);
        });
    }
    $this->info("{$enviados} mensagem(ns) enviada(s).");
})->purpose('Envia lembretes de revisão e de agenda pelo WhatsApp');

/*
 * Backup do banco (MySQL via mysqldump ou cópia do SQLite) em storage/app/backups, guardando os 30 mais recentes.
 */
Artisan::command('torque:backup {--manter=30}', function () {
    $pasta = storage_path('app/backups');
    @mkdir($pasta, 0750, true);
    $nome = $pasta.'/torque-'.now()->format('Y-m-d-His');
    $con = config('database.default');
    $cfg = config("database.connections.{$con}");
    if ($cfg['driver'] === 'sqlite') {
        copy($cfg['database'], $nome.'.sqlite');
        $arquivo = $nome.'.sqlite';
    } else {
        $arquivo = $nome.'.sql.gz';
        $cmd = sprintf('MYSQL_PWD=%s mysqldump --single-transaction --routines -h %s -P %s -u %s %s | gzip > %s',
            escapeshellarg((string) $cfg['password']), escapeshellarg((string) $cfg['host']), escapeshellarg((string) $cfg['port']),
            escapeshellarg((string) $cfg['username']), escapeshellarg((string) $cfg['database']), escapeshellarg($arquivo));
        $saida = null;
        $codigo = 0;
        exec($cmd, $saida, $codigo);
        if ($codigo !== 0 || ! filesize($arquivo)) {
            $this->error('Falha no mysqldump. Confira se ele está instalado no servidor.');

            return 1;
        }
    }
    $todos = glob($pasta.'/torque-*');
    rsort($todos);
    foreach (array_slice($todos, (int) $this->option('manter')) as $velho) {
        unlink($velho);
    }
    $this->info('Backup: '.$arquivo.' ('.round(filesize($arquivo) / 1024).' KB)');

    return 0;
})->purpose('Faz backup do banco de dados');

/*
 * Desliga a verificação em duas etapas de um usuário que perdeu o celular e os códigos de recuperação.
 */
Artisan::command('torque:desativar-2-etapas {email}', function () {
    $u = User::where('email', strtolower($this->argument('email')))->first();
    if (! $u) {
        $this->error('Usuário não encontrado.');

        return 1;
    }
    $u->forceFill(['dois_fatores_segredo' => null, 'dois_fatores_ativo_em' => null, 'dois_fatores_recuperacao' => null])->save();
    \Illuminate\Support\Facades\Log::warning('2 etapas desativadas pelo servidor', ['user' => $u->id]);
    $this->info('Verificação em duas etapas desativada. No próximo acesso, ele configura de novo.');

    return 0;
})->purpose('Desativa a verificação em duas etapas de um usuário');

Artisan::command('torque:cnh-expurgar {--empresa= : Processa apenas a empresa informada} {--apply : Executa as exclusões (sem esta opção apenas simula)} {--aplicar-limite-tecnico : Permite usar o limite técnico em empresa sem política definida}', function (\App\Services\Cnh\ExpurgoCnh $expurgo) {
    // F-247-04/TORQUE-CNH-249: o expurgo permanece desligado por padrão. A chave
    // CNH_EXPURGO_ATIVO=true só deve ser ligada depois da política aprovada; sem
    // ela, nem a simulação executa.
    if (! config('cnh.expurgo_ativo', false)) {
        $this->line('Expurgo desativado nesta etapa (CNH_EXPURGO_ATIVO=false): nada foi expurgado.');

        return 0;
    }

    $empresa = $this->option('empresa');
    if ($empresa !== null && (! ctype_digit((string) $empresa) || ! Empresa::whereKey((int) $empresa)->exists())) {
        $this->error("Empresa {$empresa} não encontrada. Nada foi expurgado.");

        return 1;
    }

    $aplicar = (bool) $this->option('apply');
    $resultado = $expurgo->executar(
        $aplicar,
        $empresa === null ? null : (int) $empresa,
        (bool) $this->option('aplicar-limite-tecnico')
    );

    foreach ($resultado['empresas'] as $linha) {
        $rotulo = sprintf('Empresa %d (%s)', $linha['empresa_id'], $linha['nome']);
        if ($linha['motivo'] === 'politica_nao_definida') {
            $this->line("{$rotulo}: política de retenção não definida; nada foi expurgado. Use --aplicar-limite-tecnico para aplicar o limite técnico de {$linha['dias']} dia(s).");

            continue;
        }
        $this->line(sprintf(
            '%s: retenção %d dia(s) [origem: %s]; corte %s; antigos %d (elegíveis %d, preservados %d); recentes %d; removidos %d [%s]',
            $rotulo,
            $linha['dias'],
            $linha['origem'],
            $linha['limite'],
            $linha['antigos'],
            $linha['elegiveis'],
            $linha['preservados'],
            $linha['recentes'],
            $linha['removidos'],
            $linha['situacao']
        ));
    }

    if ($aplicar) {
        $this->info("Expurgo aplicado: {$resultado['removidos']} registro(s) removido(s).");
    } else {
        $this->info("Expurgo em simulação: {$resultado['elegiveis']} registro(s) elegível(is); nada foi excluído. Use --apply para executar.");
    }

    return 0;
})->purpose('Expurga evidências de consulta conforme o prazo de retenção de cada empresa');

/*
 * Autoteste do módulo CNH: ambiente, catálogo, cinco dimensões e adaptadores.
 * Não usa rede real (preventStrayRequests) e não grava no banco.
 */
Artisan::command('torque:cnh-autoteste', function () {
    $falhas = 0;
    $checar = function (bool $ok, string $descricao) use (&$falhas) {
        $this->line(($ok ? 'PASS  ' : 'FAIL  ').$descricao);
        $falhas += $ok ? 0 : 1;
    };

    Http::preventStrayRequests();

    $catalogo = app(\App\Services\Cnh\CatalogoCnh::class);
    $avaliacao = app(\App\Services\Cnh\AvaliacaoCnh::class);
    $senatran = app(\App\Services\Cnh\SenatranProvider::class);
    $ambienteOriginal = config('cnh.ambiente');

    $this->line('Ambiente configurado: '.config('cnh.ambiente'));
    foreach (array_keys(\App\Services\Cnh\CatalogoCnh::FORNECEDORES) as $codigo) {
        $this->line(sprintf('  %-12s %s', $codigo, $catalogo->implementado($codigo) ? 'conector implementado' : 'pendente (sem conector)'));
    }
    $checar($catalogo->implementado('senatran') && $catalogo->implementado('vio_serpro'), 'Catálogo marca senatran e vio_serpro como implementados');
    $checar(! $catalogo->implementado('minha_cnh') && ! $catalogo->implementado('vio_privado'), 'Catálogo não anuncia conector inexistente');

    $completo = ['categoria' => 'B', 'validade' => now()->addYear()->format('Y-m-d'), 'situacao' => 'Ativa', 'impedimentos' => 0];
    $producao = $avaliacao->avaliar('senatran', $completo, ['ambiente' => 'producao', 'autenticidade' => 'oficial', 'categorias_veiculo' => ['B']]);
    $checar($producao['liberacao'] === 'producao' && $producao['autenticidade'] === 'oficial' && $producao['resultado'] === 'conferido', 'SENATRAN em produção com dados completos libera a operação');
    $homolog = $avaliacao->avaliar('senatran', $completo, ['ambiente' => 'homologacao', 'autenticidade' => 'oficial', 'categorias_veiculo' => ['B']]);
    $checar($homolog['liberacao'] === 'homologacao' && $homolog['autenticidade'] === 'oficial_homologacao', 'SENATRAN em homologação não libera a operação');
    $vencida = $avaliacao->avaliar('senatran', ['categoria' => 'B', 'validade' => now()->subDay()->format('Y-m-d'), 'situacao' => 'Ativa', 'impedimentos' => 0], ['ambiente' => 'producao', 'categorias_veiculo' => ['B']]);
    $checar($vencida['resultado'] === 'divergente' && $vencida['liberacao'] === 'nao_liberado', 'Validade vencida gera divergência e bloqueia');
    $categoria = $avaliacao->avaliar('senatran', $completo, ['ambiente' => 'producao', 'categorias_veiculo' => ['C']]);
    $checar($categoria['resultado'] === 'divergente', 'Categoria incompatível gera divergência');
    $impedido = $avaliacao->avaliar('senatran', ['categoria' => 'B', 'validade' => now()->addYear()->format('Y-m-d'), 'situacao' => 'Ativa', 'impedimentos' => 2], ['ambiente' => 'producao']);
    $checar($impedido['resultado'] === 'divergente', 'Impedimentos geram divergência');
    $documental = $avaliacao->avaliar('vio_serpro', ['documento_teste' => 'CNH Digital'], ['ambiente' => 'homologacao']);
    $checar($documental['autenticidade'] === 'documental' && $documental['liberacao'] !== 'producao', 'Leitura documental não é tratada como autenticidade oficial');

    // F-247-01: a oficialidade vem do adaptador, nunca do nome do fornecedor.
    $checar(app(\App\Services\Cnh\SenatranByok::class)->autenticidade() === 'oficial', 'Adaptador SENATRAN declara autenticidade oficial');
    $checar(app(\App\Services\Cnh\VioByok::class)->autenticidade() === 'documental', 'Adaptador Vio declara autenticidade documental');
    $checar($catalogo->fonte('senatran') === 'oficial', 'Catálogo reconhece somente SENATRAN como fonte oficial');
    $checar($catalogo->fonte('minha_cnh') === 'documental' && $catalogo->fonte('vio_privado') === 'documental', 'Fornecedores sem conector nunca são oficiais');
    $minhaCnh = $avaliacao->avaliar('minha_cnh', $completo, ['ambiente' => 'producao', 'autenticidade' => 'oficial', 'categorias_veiculo' => ['B']]);
    $checar($minhaCnh['autenticidade'] !== 'oficial' && $minhaCnh['liberacao'] !== 'producao', 'minha_cnh não consegue declarar autenticidade oficial');
    $vioPrivado = $avaliacao->avaliar('vio_privado', ['documento_teste' => 'CNH Digital'], ['ambiente' => 'producao', 'autenticidade' => 'oficial']);
    $checar($vioPrivado['autenticidade'] === 'documental' && $vioPrivado['liberacao'] !== 'producao', 'vio_privado não consegue declarar autenticidade oficial');
    $vioOficial = $avaliacao->avaliar('vio_serpro', ['documento_teste' => 'CNH Digital'], ['ambiente' => 'producao', 'autenticidade' => 'oficial']);
    $checar($vioOficial['autenticidade'] === 'documental' && $vioOficial['liberacao'] !== 'producao', 'vio_serpro rebaixa declaração oficial para documental');
    $declaracaoInvalida = $avaliacao->avaliar('senatran', $completo, ['ambiente' => 'producao', 'autenticidade' => 'oficial_falso', 'categorias_veiculo' => ['B']]);
    $checar($declaracaoInvalida['autenticidade'] !== 'oficial' && $declaracaoInvalida['liberacao'] !== 'producao', 'Declaração de autenticidade desconhecida é fail-closed');
    $checar(array_keys($producao['dimensoes']) === array_keys(\App\Services\Cnh\AvaliacaoCnh::DIMENSOES), 'Avaliação devolve as cinco dimensões na ordem esperada');

    $urlProducao = trim((string) config('cnh.endpoints.senatran.producao'));
    config(['cnh.ambiente' => 'producao']);
    if ($urlProducao === '') {
        $recusou = null;
        try {
            $senatran->consultar(['certificado' => '/dev/null', 'chave' => '/dev/null', 'cpf_operador' => '52998224725'], '52998224725', '12345678901');
        } catch (\DomainException $erro) {
            $recusou = $erro->getMessage();
        }
        $checar(is_string($recusou) && str_contains($recusou, 'não está configurado'), 'Sem CNH_SENATRAN_URL_PRODUCAO a consulta é recusada antes de qualquer envio');
        $checar(Http::recorded()->count() === 0, 'Nenhuma requisição HTTP foi enviada na recusa por falta de endpoint');
    } else {
        $checar($senatran->endpoint('producao') === rtrim($urlProducao, '/'), 'CNH_SENATRAN_URL_PRODUCAO configurada e resolvida');
    }
    config(['cnh.ambiente' => $ambienteOriginal]);

    $endpoint = '';
    try {
        $endpoint = $senatran->endpoint('homologacao');
        $checar(true, 'Endpoint de homologação da SENATRAN resolvido: '.$endpoint);
    } catch (\DomainException $erro) {
        $checar(false, 'Endpoint de homologação da SENATRAN: '.$erro->getMessage());
    }

    $chave = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    $csr = $chave ? openssl_csr_new(['commonName' => 'autoteste.torque.local'], $chave, ['digest_alg' => 'sha256']) : false;
    $cert = $csr ? openssl_csr_sign($csr, null, $chave, 1, ['digest_alg' => 'sha256']) : false;
    $pemCert = '';
    $pemKey = '';
    $gerou = $cert && openssl_x509_export($cert, $pemCert) && openssl_pkey_export($chave, $pemKey);
    $checar((bool) $gerou, 'Certificado e chave de teste gerados em memória');

    if ($gerou && $endpoint !== '') {
        Http::fake([$endpoint.'/v1/condutores/*' => Http::response(['condutores' => [[
            'categoriaAtual' => 'B', 'dataValidadeCnh' => now()->addYear()->format('Y-m-d'),
            'descricaoSituacaoCnh' => 'Ativa', 'quantidadeOcorrenciasImpedimentos' => 0,
        ]]], 200)]);
        try {
            $bruto = app(\App\Services\Cnh\SenatranByok::class)->consultar(
                ['certificado_pem' => $pemCert, 'chave_pem' => $pemKey, 'cpf_operador' => '52998224725'],
                ['cpf' => '52998224725', 'registro' => '12345678901']
            );
            $av = $avaliacao->avaliar('senatran', $bruto, ['ambiente' => $bruto['ambiente'] ?? null, 'categorias_veiculo' => ['B']]);
            $checar($bruto['categoria'] === 'B' && $bruto['ambiente'] === 'homologacao', 'Adaptador SENATRAN interpreta a resposta simulada');
            $checar($av['resultado'] === 'conferido' && $av['liberacao'] === 'homologacao', 'Resposta simulada confere, mas continua sem liberar em homologação');
        } catch (\Throwable $erro) {
            $checar(false, 'Adaptador SENATRAN: '.get_class($erro).' - '.$erro->getMessage());
        }
    }

    $vioBase = '';
    try {
        $vioBase = app(\App\Services\Cnh\VioByok::class)->baseUrl('homologacao');
        $checar(true, 'Endpoint do SERPRO/Vio resolvido: '.$vioBase);
    } catch (\DomainException $erro) {
        $checar(false, 'Endpoint do SERPRO/Vio: '.$erro->getMessage());
    }
    if ($vioBase !== '') {
        Http::fake([
            $vioBase.(string) config('cnh.endpoints.vio.token', '/token') => Http::response(['access_token' => 'token-de-teste'], 200),
            $vioBase.(string) config('cnh.endpoints.vio.decode', '/viodec-trial/v1/decode') => Http::response(['template' => ['name' => 'CNH Digital']], 200),
        ]);
        try {
            $brutoVio = app(\App\Services\Cnh\VioByok::class)->consultar(['consumer_key' => 'chave', 'consumer_secret' => 'segredo'], ['qr' => 'BINARIO-DE-TESTE']);
            $avVio = $avaliacao->avaliar('vio_serpro', $brutoVio, ['ambiente' => $brutoVio['ambiente'] ?? null]);
            $checar($brutoVio['documento_teste'] === 'CNH Digital', 'Adaptador Vio autentica e decodifica com resposta simulada');
            $checar($avVio['autenticidade'] === 'documental' && $avVio['liberacao'] !== 'producao', 'Vio nunca libera operação de produção');
        } catch (\Throwable $erro) {
            $checar(false, 'Adaptador Vio: '.get_class($erro).' - '.$erro->getMessage());
        }
    }

    config(['cnh.ambiente' => $ambienteOriginal]);
    $this->newLine();
    if ($falhas === 0) {
        $this->info('AUTOTESTE CNH: PASS - nenhuma consulta real, nenhuma escrita no banco.');

        return 0;
    }
    $this->error('AUTOTESTE CNH: '.$falhas.' falha(s).');

    return 1;
})->purpose('Autoteste do módulo CNH: ambiente, catálogo, dimensões e adaptadores (sem rede real e sem escrita no banco)');

Schedule::command('torque:cnh-expurgar')->dailyAt('03:25')->withoutOverlapping();
Schedule::command('torque:backup')->dailyAt('02:30')->withoutOverlapping();
Schedule::command('torque:notas')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('torque:lembretes')->dailyAt('09:00')->withoutOverlapping();

/*
 * Importa a exportação do Oficina Inteligente (veja docs/importacao-oficina-inteligente.md).
 * --simular roda tudo e desfaz no final, só para ver os totais e os relatórios.
 */
Artisan::command('autoconfianca:importar {pasta : pasta com Estoque_Completo, Clientes, Fornecedores, Contas_a_Receber e Contas_a_Pagar (.xlsx)} {--os= : pasta com os OS_com_Itens_*.xlsx} {--simular}', function () {
    $pasta = rtrim($this->argument('pasta'), '/');
    $achar = function (string $dir, string $prefixo): ?string {
        $xlsx = glob("{$dir}/{$prefixo}*.xlsx") ?: [];
        if (! $xlsx && glob("{$dir}/{$prefixo}*.xls")) {
            $this->error("{$prefixo}: o arquivo está em .xls. Converta para .xlsx (veja a documentação) e rode de novo.");
        }

        return $xlsx[0] ?? null;
    };
    $empresa = Empresa::query()->orderBy('id')->first();
    if (! $empresa) {
        $this->error('Crie a empresa antes (php artisan torque:empresa ...).');

        return 1;
    }
    $osArquivos = [];
    if ($dirOs = $this->option('os')) {
        $osArquivos = glob(rtrim($dirOs, '/').'/OS_com_Itens*.xlsx') ?: [];
        if (! $osArquivos) {
            $this->error('Nenhum OS_com_Itens*.xlsx em '.$dirOs.(glob(rtrim($dirOs, '/').'/OS_com_Itens*.xls') ? ' (há arquivos .xls: converta para .xlsx).' : '.'));

            return 1;
        }
    }
    $imp = new \App\Services\Importacao\OficinaInteligente;
    $simular = (bool) $this->option('simular');

    try {
        Tenant::com($empresa->id, function () use ($imp, $achar, $pasta, $osArquivos, $simular) {
            DB::beginTransaction();
            foreach (['produtos' => 'Estoque_Completo', 'clientes' => 'Clientes', 'fornecedores' => 'Fornecedores'] as $metodo => $prefixo) {
                if ($arq = $achar($pasta, $prefixo)) {
                    $this->line("Importando {$metodo}…");
                    $imp->{$metodo}($arq);
                }
            }
            if ($osArquivos) {
                $this->line('Importando OS antigas e veículos ('.count($osArquivos).' arquivos)…');
                $imp->ordensDeServico($osArquivos);
            }
            if ($arq = $achar($pasta, 'Contas_a_Receber')) {
                $this->line('Importando contas a receber…');
                $imp->contasAReceber($arq);
            }
            if ($arq = $achar($pasta, 'Contas_a_Pagar')) {
                $this->line('Importando contas a pagar…');
                $imp->contasAPagar($arq);
            }
            $simular ? DB::rollBack() : DB::commit();
        });
    } catch (\Throwable $e) {
        DB::rollBack();
        $this->error('Nada foi gravado. Erro: '.$e->getMessage());

        return 1;
    }

    $this->newLine();
    $this->info($simular ? 'SIMULAÇÃO: nada foi gravado.' : 'Importação concluída.');
    foreach ($imp->totais as $k => $v) {
        $this->line(str_pad($k, 18).$v);
    }
    $dir = storage_path('app/importacao/'.now()->format('Y-m-d_His'));
    @mkdir($dir, 0750, true);
    $this->newLine();
    $this->line('Relatórios para conferir (em '.$dir.'):');
    foreach ($imp->relatorios as $nome => $linhas) {
        $f = fopen("{$dir}/{$nome}.csv", 'w');
        fwrite($f, "\xEF\xBB\xBF");
        foreach ($linhas as $l) {
            fputcsv($f, $l, ';');
        }
        fclose($f);
        $this->line(str_pad($nome, 28).(count($linhas) - 1).' linha(s)');
    }

    return 0;
})->purpose('Importa produtos, clientes, fornecedores, veículos, OS antigas, contas a receber e contas a pagar do Oficina Inteligente');

/*
 * Catálogo de serviços de manutenção periódica com prazo (troca de óleo, revisão, filtros...).
 * Só cria o que falta e preenche prazo vazio; não mexe em preço nem em prazo já preenchido.
 */
Artisan::command('autoconfianca:servicos-padrao', function () {
    $empresa = Empresa::query()->orderBy('id')->first();
    if (! $empresa) {
        $this->error('Crie a empresa antes (php artisan torque:empresa ...).');

        return 1;
    }
    $r = Tenant::com($empresa->id, fn () => app(\App\Services\Lembretes\Revisoes::class)->servicosPadrao());
    $this->info(count($r['criados']).' serviço(s) criado(s)'.($r['criados'] ? ': '.implode(', ', $r['criados']) : '').'.');
    if ($r['prazos']) {
        $this->line('Prazo preenchido em: '.implode(', ', $r['prazos']).'.');
    }
    $this->line('Confira preços e prazos em Catálogo de serviços.');

    return 0;
})->purpose('Cadastra os serviços de manutenção periódica com prazo de revisão');

/*
 * Lembretes de revisão a partir das OS antigas do Oficina Inteligente (para o dono atual de cada carro).
 * --simular mostra os totais e gera o relatório sem gravar.
 */
Artisan::command('autoconfianca:lembretes-historico {--simular}', function () {
    $empresa = Empresa::query()->orderBy('id')->first();
    if (! $empresa) {
        $this->error('Crie a empresa antes (php artisan torque:empresa ...).');

        return 1;
    }
    $simular = (bool) $this->option('simular');
    $r = Tenant::com($empresa->id, function () use ($simular) {
        DB::beginTransaction();
        try {
            $r = app(\App\Services\Lembretes\Revisoes::class)->lembretesDoHistorico();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
        $simular ? DB::rollBack() : DB::commit();

        return $r;
    });
    if ($r['sem_catalogo']) {
        $this->warn('Sem prazo no catálogo (rode autoconfianca:servicos-padrao): '.implode(', ', $r['sem_catalogo']));
    }
    $this->info(($simular ? 'SIMULAÇÃO: nada foi gravado. ' : '').$r['criados'].' lembrete(s) '.($simular ? 'seriam criados' : 'criados').'.');
    foreach ($r['por_servico'] as $nome => $n) {
        $this->line(str_pad($nome, 38).$n);
    }
    $cota = max(1, (int) config('whatsapp.lembretes_atrasados_por_dia', 20));
    $this->newLine();
    $this->line("Atrasados (venceram nos últimos ".\App\Services\WhatsApp\EnvioLembretes::DIAS_ATRASO_MAX." dias): {$r['atrasados']}  → saem {$cota} por dia, uns ".(int) ceil($r['atrasados'] / $cota).' dia(s)');
    $this->line("Vencem nos próximos 30 dias: {$r['proximos_30']}  → saem 7 dias antes de vencer");
    $this->line("Depois: {$r['depois']}");
    $this->line("Ignorados: {$r['ignorados_antigos']} vencidos há mais de ".\App\Services\WhatsApp\EnvioLembretes::DIAS_ATRASO_MAX." dias, {$r['ignorados_dono']} de carros que mudaram de dono ou sem dono no cadastro");
    $dir = storage_path('app/importacao/'.now()->format('Y-m-d_His'));
    @mkdir($dir, 0750, true);
    $f = fopen("{$dir}/lembretes_historico.csv", 'w');
    fwrite($f, "\xEF\xBB\xBF");
    foreach ($r['linhas'] as $l) {
        fputcsv($f, $l, ';');
    }
    fclose($f);
    $this->line('Lista completa: '.$dir.'/lembretes_historico.csv');

    return 0;
})->purpose('Cria lembretes de revisão/troca de óleo a partir das OS antigas do Oficina Inteligente');

/*
 * Confere a cada 10 minutos se o celular da oficina está conectado na Evolution.
 * Desconectado: aparece um aviso no painel. Ao reconectar: reenvia o que falhou nas últimas 24 h.
 * (Com o webhook ligado, a Evolution também avisa na hora pelo evento connection.update.)
 */
Artisan::command('torque:whatsapp-estado', function (\App\Services\WhatsApp\WhatsApp $whatsapp) {
    foreach (Empresa::where('ativo', true)->whereNotNull('whatsapp_instancia')->get() as $empresa) {
        $estado = $whatsapp->estado($empresa);
        if ($estado === null) {
            continue; // Evolution não respondeu: não muda o que se sabia
        }
        Tenant::com($empresa->id, function () use ($empresa, $estado, $whatsapp) {
            $antes = $empresa->whatsapp_estado;
            $empresa->forceFill(['whatsapp_estado' => $estado, 'whatsapp_estado_em' => now()])->save();
            if ($estado === 'open' && $antes && $antes !== 'open') {
                $whatsapp->reenviarFalhas($empresa, 24, 5);
            }
            if ($estado !== 'open' && $antes !== $estado) {
                \Illuminate\Support\Facades\Log::warning('WhatsApp da oficina desconectado', ['empresa' => $empresa->id, 'estado' => $estado]);
            }
        });
        $this->line("{$empresa->nome_fantasia}: {$estado}");
    }
})->purpose('Confere se o WhatsApp da oficina está conectado na Evolution');

Schedule::command('torque:whatsapp-estado')->everyTenMinutes()->withoutOverlapping();
