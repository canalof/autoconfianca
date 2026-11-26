<?php

/*
 * Dados públicos da oficina usados no site, na área do cliente e nas mensagens.
 * Os campos que dependem do cliente vêm do .env (OFICINA_*). Enquanto não forem
 * preenchidos, o site mostra o texto entre colchetes: confira antes de publicar.
 */
return [
    'nome' => env('OFICINA_NOME', 'AutoConfiança'),
    'slogan' => env('OFICINA_SLOGAN', 'Mecânica, suspensão, freios e revisão'),
    'dominio' => rtrim(env('APP_URL', 'https://confiancarev.com.br'), '/'),
    'whatsapp' => env('OFICINA_WHATSAPP', ''),
    'telefone' => env('OFICINA_TELEFONE', '[Telefone]'),
    'endereco' => [
        'rua' => env('OFICINA_RUA', '[Rua, número]'),
        'bairro' => env('OFICINA_BAIRRO', '[Bairro]'),
        'cidade' => env('OFICINA_CIDADE', '[Cidade]'),
        'uf' => env('OFICINA_UF', 'SP'),
        'cep' => env('OFICINA_CEP', '[00000-000]'),
    ],
    'horarios' => [
        [
            'dias' => 'Segunda a sexta',
            'horas' => '08h às 18h',
            'schema' => [
                'Monday',
                'Tuesday',
                'Wednesday',
                'Thursday',
                'Friday',
            ],
            'abre' => '08:00',
            'fecha' => '18:00',
        ],
        [
            'dias' => 'Sábado',
            'horas' => '08h às 12h',
            'schema' => [
                'Saturday',
            ],
            'abre' => '08:00',
            'fecha' => '12:00',
        ],
    ],
    'instagram' => env('OFICINA_INSTAGRAM', ''),
    'google_avaliacoes' => env('OFICINA_GOOGLE_AVALIACOES', ''),
    'anos_de_oficina' => env('OFICINA_ANOS', ''),
    'servicos' => [
        [
            'slug' => 'troca-de-oleo',
            'nome' => 'Troca de óleo e filtros',
            'resumo' => 'Óleo indicado pelo manual do seu carro, troca de filtro de óleo, ar e combustível.',
            'itens' => [
                'Óleo conforme especificação da montadora',
                'Filtros de óleo, ar, combustível e cabine',
                'Conferência de níveis e vazamentos',
            ],
        ],
        [
            'slug' => 'freios',
            'nome' => 'Freios',
            'resumo' => 'Pastilhas, discos, lonas e fluido de freio, com teste depois do serviço.',
            'itens' => [
                'Troca de pastilhas e discos',
                'Lonas e tambores',
                'Troca do fluido de freio',
                'Revisão do sistema ABS',
            ],
        ],
        [
            'slug' => 'suspensao',
            'nome' => 'Suspensão',
            'resumo' => 'Amortecedores, bandejas, buchas, pivôs e terminais.',
            'itens' => [
                'Amortecedores e molas',
                'Bandejas, buchas e pivôs',
                'Terminais e barras axiais',
                'Coxins',
            ],
        ],
        [
            'slug' => 'alinhamento-e-balanceamento',
            'nome' => 'Alinhamento e balanceamento',
            'resumo' => 'Carro andando reto, sem volante torto nem pneu gastando de um lado só.',
            'itens' => [
                'Alinhamento computadorizado',
                'Balanceamento das rodas',
                'Rodízio de pneus',
            ],
        ],
        [
            'slug' => 'revisao',
            'nome' => 'Revisão preventiva',
            'resumo' => 'Checagem completa antes de viagem ou na quilometragem do manual.',
            'itens' => [
                'Checklist de itens de segurança',
                'Correias, velas e fluidos',
                'Relatório do que precisa e do que pode esperar',
            ],
        ],
        [
            'slug' => 'ar-condicionado',
            'nome' => 'Ar-condicionado',
            'resumo' => 'Higienização, recarga de gás e conserto de vazamentos.',
            'itens' => [
                'Higienização e troca do filtro de cabine',
                'Recarga de gás',
                'Detecção de vazamentos',
            ],
        ],
        [
            'slug' => 'injecao-eletronica',
            'nome' => 'Injeção e diagnóstico',
            'resumo' => 'Leitura do scanner, luz de injeção acesa e falhas no motor.',
            'itens' => [
                'Diagnóstico com scanner',
                'Limpeza de bicos',
                'Sensores e velas de ignição',
            ],
        ],
    ],
    'sintomas' => [
        [
            'id' => 'freio',
            'texto' => 'Barulho ao frear',
            'explica' => 'Chiado ou rangido ao frear costuma ser pastilha no fim ou disco desgastado.',
            'servico' => 'freios',
        ],
        [
            'id' => 'puxando',
            'texto' => 'Carro puxando para um lado',
            'explica' => 'Geralmente é alinhamento, pneu com pressão diferente ou peça da suspensão gasta.',
            'servico' => 'alinhamento-e-balanceamento',
        ],
        [
            'id' => 'batida',
            'texto' => 'Barulho em buraco ou lombada',
            'explica' => 'Batidas secas costumam vir de amortecedor, bucha, pivô ou coxim.',
            'servico' => 'suspensao',
        ],
        [
            'id' => 'luz',
            'texto' => 'Luz acesa no painel',
            'explica' => 'O scanner lê o código da falha e mostra onde está o problema.',
            'servico' => 'injecao-eletronica',
        ],
        [
            'id' => 'ar',
            'texto' => 'Ar não gela',
            'explica' => 'Pode ser falta de gás, vazamento ou filtro de cabine sujo.',
            'servico' => 'ar-condicionado',
        ],
        [
            'id' => 'volante',
            'texto' => 'Volante tremendo',
            'explica' => 'Tremor em velocidade costuma ser balanceamento; ao frear, disco empenado.',
            'servico' => 'alinhamento-e-balanceamento',
        ],
        [
            'id' => 'oleo',
            'texto' => 'Passou da troca de óleo',
            'explica' => 'Óleo vencido perde proteção e aumenta o desgaste do motor.',
            'servico' => 'troca-de-oleo',
        ],
        [
            'id' => 'viagem',
            'texto' => 'Vou viajar',
            'explica' => 'Uma revisão antes da estrada confere freios, pneus, fluidos e correias.',
            'servico' => 'revisao',
        ],
    ],
];
