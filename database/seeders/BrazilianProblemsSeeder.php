<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class BrazilianProblemsSeeder extends Seeder
{
    public function run(): void
    {
        $problems = array_merge(
            $this->getMaratonaSBC2024(),
            $this->getMaratonaSBC2023(),
            $this->getMaratonaSBC2022(),
            $this->getMaratonaSBC2021(),
            $this->getOBI2023(),
            $this->getOBI2022(),
            $this->getOBI2021(),
            $this->getOBI2019(),
            $this->getOBI2018(),
            $this->getOBI2017(),
            $this->getOBI2016(),
            $this->getOBI2015(),
            $this->getClassicProblems()
        );

        foreach ($problems as $problem) {
            // Check if problem exists
            $exists = DB::table('problem_bank')
                ->where('code', $problem['code'])
                ->exists();

            if (!$exists) {
                DB::table('problem_bank')->insert(array_merge($problem, [
                    'created_at' => now(),
                    'updated_at' => now(),
                ]));
            }
        }

        $this->command->info('Brazilian problems seeded: ' . count($problems) . ' problems');
    }

    protected function getMaratonaSBC2024(): array
    {
        $base = [
            'source' => 'Maratona SBC 2024',
            'source_url' => 'https://maratona.sbc.org.br/hist/2024/',
            'time_limit' => 1,
            'memory_limit' => 256,
            'is_active' => true,
        ];

        return [
            array_merge($base, [
                'code' => 'SBC24_A',
                'name' => 'Append and Panic!',
                'description' => 'Problema da Maratona de Programacao SBC 2024 - Primeira Fase. Envolve manipulacao de strings e operacoes de adicao.',
                'input_description' => 'A entrada consiste em multiplos casos de teste conforme especificado no problema.',
                'output_description' => 'Para cada caso de teste, imprima a resposta conforme especificado.',
                'difficulty' => 'medium',
                'tags' => json_encode(['strings', 'implementacao', 'maratona']),
            ]),
            array_merge($base, [
                'code' => 'SBC24_B',
                'name' => 'Biketopia\'s Cyclic Track',
                'description' => 'Problema envolvendo grafos e ciclos em uma pista de ciclismo.',
                'input_description' => 'A entrada consiste em multiplos casos de teste.',
                'output_description' => 'Para cada caso, imprima a resposta.',
                'difficulty' => 'hard',
                'tags' => json_encode(['grafos', 'ciclos', 'maratona']),
            ]),
            array_merge($base, [
                'code' => 'SBC24_C',
                'name' => 'Cindy\'s Christmas Challenge',
                'description' => 'Desafio de Natal da Cindy - problema de otimizacao.',
                'input_description' => 'Entrada conforme especificacao.',
                'output_description' => 'Saida conforme especificacao.',
                'difficulty' => 'medium',
                'tags' => json_encode(['otimizacao', 'maratona']),
            ]),
            array_merge($base, [
                'code' => 'SBC24_D',
                'name' => 'Diverse T-Shirts',
                'description' => 'Problema sobre diversidade de camisetas - combinatoria.',
                'input_description' => 'Entrada conforme especificacao.',
                'output_description' => 'Saida conforme especificacao.',
                'difficulty' => 'medium',
                'tags' => json_encode(['combinatoria', 'maratona']),
            ]),
            array_merge($base, [
                'code' => 'SBC24_E',
                'name' => 'Evereth Expedition',
                'description' => 'Expedicao ao Evereste - problema de busca e exploracao.',
                'input_description' => 'Entrada conforme especificacao.',
                'output_description' => 'Saida conforme especificacao.',
                'difficulty' => 'hard',
                'tags' => json_encode(['busca', 'grafos', 'maratona']),
            ]),
            array_merge($base, [
                'code' => 'SBC24_F',
                'name' => 'Finding Privacy',
                'description' => 'Encontrando privacidade - problema de geometria ou busca.',
                'input_description' => 'Entrada conforme especificacao.',
                'output_description' => 'Saida conforme especificacao.',
                'difficulty' => 'hard',
                'tags' => json_encode(['geometria', 'maratona']),
            ]),
            array_merge($base, [
                'code' => 'SBC24_G',
                'name' => 'Grand Glory Race',
                'description' => 'Grande corrida de gloria - simulacao ou grafos.',
                'input_description' => 'Entrada conforme especificacao.',
                'output_description' => 'Saida conforme especificacao.',
                'difficulty' => 'hard',
                'tags' => json_encode(['simulacao', 'grafos', 'maratona']),
            ]),
            array_merge($base, [
                'code' => 'SBC24_H',
                'name' => 'Heraclosures',
                'description' => 'Problema envolvendo fechamentos e estruturas de dados.',
                'input_description' => 'Entrada conforme especificacao.',
                'output_description' => 'Saida conforme especificacao.',
                'difficulty' => 'hard',
                'tags' => json_encode(['estruturas', 'maratona']),
            ]),
            array_merge($base, [
                'code' => 'SBC24_I',
                'name' => 'Inversion Insight',
                'description' => 'Problema sobre inversoes em arrays ou permutacoes.',
                'input_description' => 'Entrada conforme especificacao.',
                'output_description' => 'Saida conforme especificacao.',
                'difficulty' => 'medium',
                'tags' => json_encode(['arrays', 'inversoes', 'maratona']),
            ]),
            array_merge($base, [
                'code' => 'SBC24_J',
                'name' => 'Jigsaw of Shadows',
                'description' => 'Quebra-cabeca de sombras - problema de logica.',
                'input_description' => 'Entrada conforme especificacao.',
                'output_description' => 'Saida conforme especificacao.',
                'difficulty' => 'hard',
                'tags' => json_encode(['logica', 'maratona']),
            ]),
            array_merge($base, [
                'code' => 'SBC24_K',
                'name' => 'Kool Strings',
                'description' => 'Strings legais - manipulacao de strings.',
                'input_description' => 'Entrada conforme especificacao.',
                'output_description' => 'Saida conforme especificacao.',
                'difficulty' => 'medium',
                'tags' => json_encode(['strings', 'maratona']),
            ]),
            array_merge($base, [
                'code' => 'SBC24_L',
                'name' => 'Latin Squares',
                'description' => 'Quadrados latinos - problema de combinatoria e matrizes.',
                'input_description' => 'Entrada conforme especificacao.',
                'output_description' => 'Saida conforme especificacao.',
                'difficulty' => 'hard',
                'tags' => json_encode(['matrizes', 'combinatoria', 'maratona']),
            ]),
        ];
    }

    protected function getMaratonaSBC2023(): array
    {
        $base = [
            'source' => 'Maratona SBC 2023',
            'source_url' => 'https://maratona.sbc.org.br/hist/2023/',
            'time_limit' => 1,
            'memory_limit' => 256,
            'is_active' => true,
        ];

        return [
            array_merge($base, [
                'code' => 'SBC23_A',
                'name' => 'Analyzing Contracts',
                'description' => 'Analise de contratos - problema de parsing ou strings.',
                'input_description' => 'Entrada conforme especificacao.',
                'output_description' => 'Saida conforme especificacao.',
                'difficulty' => 'medium',
                'tags' => json_encode(['strings', 'parsing', 'maratona']),
            ]),
            array_merge($base, [
                'code' => 'SBC23_B',
                'name' => 'Blackboard Game',
                'description' => 'Jogo do quadro negro - teoria dos jogos.',
                'input_description' => 'Entrada conforme especificacao.',
                'output_description' => 'Saida conforme especificacao.',
                'difficulty' => 'medium',
                'tags' => json_encode(['jogos', 'maratona']),
            ]),
            array_merge($base, [
                'code' => 'SBC23_C',
                'name' => 'Candy Rush',
                'description' => 'Corrida de doces - otimizacao ou guloso.',
                'input_description' => 'Entrada conforme especificacao.',
                'output_description' => 'Saida conforme especificacao.',
                'difficulty' => 'easy',
                'tags' => json_encode(['guloso', 'maratona']),
            ]),
            array_merge($base, [
                'code' => 'SBC23_D',
                'name' => 'Deciphering WordWhiz',
                'description' => 'Decifrando palavras - manipulacao de strings.',
                'input_description' => 'Entrada conforme especificacao.',
                'output_description' => 'Saida conforme especificacao.',
                'difficulty' => 'easy',
                'tags' => json_encode(['strings', 'maratona']),
            ]),
            array_merge($base, [
                'code' => 'SBC23_E',
                'name' => 'Elevated Profits',
                'description' => 'Lucros elevados - programacao dinamica.',
                'input_description' => 'Entrada conforme especificacao.',
                'output_description' => 'Saida conforme especificacao.',
                'difficulty' => 'hard',
                'tags' => json_encode(['dp', 'maratona']),
            ]),
            array_merge($base, [
                'code' => 'SBC23_F',
                'name' => 'Forward and Backward',
                'description' => 'Para frente e para tras - palindromos ou strings.',
                'input_description' => 'Entrada conforme especificacao.',
                'output_description' => 'Saida conforme especificacao.',
                'difficulty' => 'medium',
                'tags' => json_encode(['strings', 'palindromo', 'maratona']),
            ]),
            array_merge($base, [
                'code' => 'SBC23_G',
                'name' => 'GPS on a Flat Earth',
                'description' => 'GPS em uma Terra plana - geometria computacional.',
                'input_description' => 'Entrada conforme especificacao.',
                'output_description' => 'Saida conforme especificacao.',
                'difficulty' => 'hard',
                'tags' => json_encode(['geometria', 'maratona']),
            ]),
            array_merge($base, [
                'code' => 'SBC23_H',
                'name' => 'Health in Hazard',
                'description' => 'Saude em perigo - simulacao ou grafos.',
                'input_description' => 'Entrada conforme especificacao.',
                'output_description' => 'Saida conforme especificacao.',
                'difficulty' => 'hard',
                'tags' => json_encode(['simulacao', 'maratona']),
            ]),
            array_merge($base, [
                'code' => 'SBC23_I',
                'name' => 'Inversions',
                'description' => 'Inversoes - contagem de inversoes em arrays.',
                'input_description' => 'Entrada conforme especificacao.',
                'output_description' => 'Saida conforme especificacao.',
                'difficulty' => 'medium',
                'tags' => json_encode(['arrays', 'merge sort', 'maratona']),
            ]),
            array_merge($base, [
                'code' => 'SBC23_J',
                'name' => 'Journey of the Robber',
                'description' => 'Jornada do ladrao - grafos ou DP.',
                'input_description' => 'Entrada conforme especificacao.',
                'output_description' => 'Saida conforme especificacao.',
                'difficulty' => 'hard',
                'tags' => json_encode(['grafos', 'dp', 'maratona']),
            ]),
            array_merge($base, [
                'code' => 'SBC23_K',
                'name' => 'Keen on Order',
                'description' => 'Ansioso por ordem - ordenacao ou estruturas.',
                'input_description' => 'Entrada conforme especificacao.',
                'output_description' => 'Saida conforme especificacao.',
                'difficulty' => 'medium',
                'tags' => json_encode(['ordenacao', 'maratona']),
            ]),
            array_merge($base, [
                'code' => 'SBC23_L',
                'name' => 'Latam++',
                'description' => 'Latam++ - problema de implementacao.',
                'input_description' => 'Entrada conforme especificacao.',
                'output_description' => 'Saida conforme especificacao.',
                'difficulty' => 'easy',
                'tags' => json_encode(['implementacao', 'maratona']),
            ]),
            array_merge($base, [
                'code' => 'SBC23_M',
                'name' => 'Meeting Point',
                'description' => 'Ponto de encontro - geometria ou grafos.',
                'input_description' => 'Entrada conforme especificacao.',
                'output_description' => 'Saida conforme especificacao.',
                'difficulty' => 'hard',
                'tags' => json_encode(['geometria', 'grafos', 'maratona']),
            ]),
        ];
    }

    protected function getMaratonaSBC2022(): array
    {
        $base = [
            'source' => 'Maratona SBC 2022',
            'source_url' => 'https://maratona.sbc.org.br/hist/2022/',
            'time_limit' => 1,
            'memory_limit' => 256,
            'is_active' => true,
        ];

        return [
            array_merge($base, ['code' => 'SBC22_A', 'name' => 'Asking for Money', 'description' => 'Pedindo dinheiro - problema de otimizacao.', 'input_description' => 'Entrada conforme especificacao.', 'output_description' => 'Saida conforme especificacao.', 'difficulty' => 'easy', 'tags' => json_encode(['matematica', 'maratona'])]),
            array_merge($base, ['code' => 'SBC22_B', 'name' => 'Board Game', 'description' => 'Jogo de tabuleiro - teoria dos jogos.', 'input_description' => 'Entrada conforme especificacao.', 'output_description' => 'Saida conforme especificacao.', 'difficulty' => 'medium', 'tags' => json_encode(['jogos', 'maratona'])]),
            array_merge($base, ['code' => 'SBC22_C', 'name' => 'City Folding', 'description' => 'Dobradura de cidade - geometria.', 'input_description' => 'Entrada conforme especificacao.', 'output_description' => 'Saida conforme especificacao.', 'difficulty' => 'hard', 'tags' => json_encode(['geometria', 'maratona'])]),
            array_merge($base, ['code' => 'SBC22_D', 'name' => 'Daily Trips', 'description' => 'Viagens diarias - grafos e caminhos.', 'input_description' => 'Entrada conforme especificacao.', 'output_description' => 'Saida conforme especificacao.', 'difficulty' => 'medium', 'tags' => json_encode(['grafos', 'maratona'])]),
            array_merge($base, ['code' => 'SBC22_E', 'name' => 'Empty Squares', 'description' => 'Quadrados vazios - contagem.', 'input_description' => 'Entrada conforme especificacao.', 'output_description' => 'Saida conforme especificacao.', 'difficulty' => 'medium', 'tags' => json_encode(['contagem', 'maratona'])]),
            array_merge($base, ['code' => 'SBC22_F', 'name' => 'Favorite Tree', 'description' => 'Arvore favorita - estruturas de dados em arvores.', 'input_description' => 'Entrada conforme especificacao.', 'output_description' => 'Saida conforme especificacao.', 'difficulty' => 'hard', 'tags' => json_encode(['arvores', 'maratona'])]),
            array_merge($base, ['code' => 'SBC22_G', 'name' => 'Gravitational Wave Detector', 'description' => 'Detector de ondas gravitacionais - simulacao.', 'input_description' => 'Entrada conforme especificacao.', 'output_description' => 'Saida conforme especificacao.', 'difficulty' => 'hard', 'tags' => json_encode(['simulacao', 'maratona'])]),
            array_merge($base, ['code' => 'SBC22_H', 'name' => 'Horse Race', 'description' => 'Corrida de cavalos - simulacao ou ordenacao.', 'input_description' => 'Entrada conforme especificacao.', 'output_description' => 'Saida conforme especificacao.', 'difficulty' => 'medium', 'tags' => json_encode(['ordenacao', 'maratona'])]),
            array_merge($base, ['code' => 'SBC22_I', 'name' => 'Italian Calzone & Pasta Corner', 'description' => 'Cantina italiana - problema de implementacao.', 'input_description' => 'Entrada conforme especificacao.', 'output_description' => 'Saida conforme especificacao.', 'difficulty' => 'easy', 'tags' => json_encode(['implementacao', 'maratona'])]),
            array_merge($base, ['code' => 'SBC22_J', 'name' => 'Joining a Marathon', 'description' => 'Entrando em uma maratona - busca ou guloso.', 'input_description' => 'Entrada conforme especificacao.', 'output_description' => 'Saida conforme especificacao.', 'difficulty' => 'medium', 'tags' => json_encode(['busca', 'maratona'])]),
            array_merge($base, ['code' => 'SBC22_K', 'name' => 'Kind Baker', 'description' => 'Padeiro gentil - problema de distribuicao.', 'input_description' => 'Entrada conforme especificacao.', 'output_description' => 'Saida conforme especificacao.', 'difficulty' => 'easy', 'tags' => json_encode(['matematica', 'maratona'])]),
            array_merge($base, ['code' => 'SBC22_L', 'name' => 'Lazy Printing', 'description' => 'Impressao preguicosa - strings ou simulacao.', 'input_description' => 'Entrada conforme especificacao.', 'output_description' => 'Saida conforme especificacao.', 'difficulty' => 'medium', 'tags' => json_encode(['strings', 'maratona'])]),
            array_merge($base, ['code' => 'SBC22_M', 'name' => 'Maze in Bolt', 'description' => 'Labirinto no parafuso - BFS ou DFS.', 'input_description' => 'Entrada conforme especificacao.', 'output_description' => 'Saida conforme especificacao.', 'difficulty' => 'hard', 'tags' => json_encode(['grafos', 'bfs', 'maratona'])]),
        ];
    }

    protected function getMaratonaSBC2021(): array
    {
        $base = [
            'source' => 'Maratona SBC 2021',
            'source_url' => 'https://maratona.sbc.org.br/hist/2021/',
            'time_limit' => 1,
            'memory_limit' => 256,
            'is_active' => true,
        ];

        return [
            array_merge($base, ['code' => 'SBC21_A', 'name' => 'Ancient Towers', 'description' => 'Torres antigas - estruturas de dados ou geometria.', 'input_description' => 'Entrada conforme especificacao.', 'output_description' => 'Saida conforme especificacao.', 'difficulty' => 'medium', 'tags' => json_encode(['estruturas', 'maratona'])]),
            array_merge($base, ['code' => 'SBC21_B', 'name' => 'Because, Art!', 'description' => 'Porque, arte! - problema criativo.', 'input_description' => 'Entrada conforme especificacao.', 'output_description' => 'Saida conforme especificacao.', 'difficulty' => 'easy', 'tags' => json_encode(['implementacao', 'maratona'])]),
            array_merge($base, ['code' => 'SBC21_C', 'name' => 'Cyclists versus Clouds', 'description' => 'Ciclistas versus nuvens - simulacao.', 'input_description' => 'Entrada conforme especificacao.', 'output_description' => 'Saida conforme especificacao.', 'difficulty' => 'medium', 'tags' => json_encode(['simulacao', 'maratona'])]),
            array_merge($base, ['code' => 'SBC21_D', 'name' => 'Daily Turnovers', 'description' => 'Rotatividade diaria - contagem ou matematica.', 'input_description' => 'Entrada conforme especificacao.', 'output_description' => 'Saida conforme especificacao.', 'difficulty' => 'medium', 'tags' => json_encode(['matematica', 'maratona'])]),
            array_merge($base, ['code' => 'SBC21_E', 'name' => 'Expedition Plans', 'description' => 'Planos de expedicao - grafos.', 'input_description' => 'Entrada conforme especificacao.', 'output_description' => 'Saida conforme especificacao.', 'difficulty' => 'hard', 'tags' => json_encode(['grafos', 'maratona'])]),
            array_merge($base, ['code' => 'SBC21_F', 'name' => 'Fields Division', 'description' => 'Divisao de campos - geometria.', 'input_description' => 'Entrada conforme especificacao.', 'output_description' => 'Saida conforme especificacao.', 'difficulty' => 'hard', 'tags' => json_encode(['geometria', 'maratona'])]),
            array_merge($base, ['code' => 'SBC21_G', 'name' => 'Generator Tree', 'description' => 'Arvore geradora - arvores e grafos.', 'input_description' => 'Entrada conforme especificacao.', 'output_description' => 'Saida conforme especificacao.', 'difficulty' => 'hard', 'tags' => json_encode(['arvores', 'grafos', 'maratona'])]),
            array_merge($base, ['code' => 'SBC21_H', 'name' => 'Hamilton - The Musical', 'description' => 'Hamilton - O Musical - caminhos hamiltonianos.', 'input_description' => 'Entrada conforme especificacao.', 'output_description' => 'Saida conforme especificacao.', 'difficulty' => 'hard', 'tags' => json_encode(['grafos', 'np', 'maratona'])]),
            array_merge($base, ['code' => 'SBC21_I', 'name' => 'Invested Money', 'description' => 'Dinheiro investido - matematica financeira.', 'input_description' => 'Entrada conforme especificacao.', 'output_description' => 'Saida conforme especificacao.', 'difficulty' => 'easy', 'tags' => json_encode(['matematica', 'maratona'])]),
            array_merge($base, ['code' => 'SBC21_J', 'name' => 'Joining Pairs', 'description' => 'Juntando pares - emparelhamento.', 'input_description' => 'Entrada conforme especificacao.', 'output_description' => 'Saida conforme especificacao.', 'difficulty' => 'medium', 'tags' => json_encode(['emparelhamento', 'maratona'])]),
            array_merge($base, ['code' => 'SBC21_K', 'name' => 'KIARA is a Recursive Acronym', 'description' => 'KIARA e um acronimo recursivo - strings.', 'input_description' => 'Entrada conforme especificacao.', 'output_description' => 'Saida conforme especificacao.', 'difficulty' => 'medium', 'tags' => json_encode(['strings', 'recursao', 'maratona'])]),
            array_merge($base, ['code' => 'SBC21_L', 'name' => 'Leaving Yharnam', 'description' => 'Deixando Yharnam - grafos e busca.', 'input_description' => 'Entrada conforme especificacao.', 'output_description' => 'Saida conforme especificacao.', 'difficulty' => 'hard', 'tags' => json_encode(['grafos', 'busca', 'maratona'])]),
            array_merge($base, ['code' => 'SBC21_M', 'name' => 'Most Ordered Way', 'description' => 'Caminho mais ordenado - ordenacao ou DP.', 'input_description' => 'Entrada conforme especificacao.', 'output_description' => 'Saida conforme especificacao.', 'difficulty' => 'hard', 'tags' => json_encode(['ordenacao', 'dp', 'maratona'])]),
        ];
    }

    protected function getOBI2023(): array
    {
        $base = [
            'source' => 'OBI 2023',
            'source_url' => 'https://olimpiada.ic.unicamp.br/passadas/OBI2023/',
            'time_limit' => 1,
            'memory_limit' => 256,
            'is_active' => true,
        ];

        return [
            array_merge($base, ['code' => 'OBI23_PREMIO', 'name' => 'Premio', 'description' => 'Calcular o premio de um jogador baseado em suas pontuacoes.', 'input_description' => 'Valores de pontuacao.', 'output_description' => 'Valor do premio.', 'difficulty' => 'easy', 'tags' => json_encode(['matematica', 'obi', 'junior'])]),
            array_merge($base, ['code' => 'OBI23_EPIDEMIA', 'name' => 'Epidemia', 'description' => 'Simular a propagacao de uma epidemia.', 'input_description' => 'Dados da populacao.', 'output_description' => 'Estado apos N dias.', 'difficulty' => 'easy', 'tags' => json_encode(['simulacao', 'obi', 'junior'])]),
            array_merge($base, ['code' => 'OBI23_CHINELOS', 'name' => 'Chinelos', 'description' => 'Problema sobre pares de chinelos.', 'input_description' => 'Lista de chinelos.', 'output_description' => 'Quantidade de pares.', 'difficulty' => 'easy', 'tags' => json_encode(['contagem', 'obi', 'junior'])]),
            array_merge($base, ['code' => 'OBI23_VAR', 'name' => 'VAR', 'description' => 'Video Assistant Referee - decisoes de jogo.', 'input_description' => 'Eventos do jogo.', 'output_description' => 'Decisao final.', 'difficulty' => 'easy', 'tags' => json_encode(['implementacao', 'obi', 'nivel1'])]),
            array_merge($base, ['code' => 'OBI23_ESTOQUE', 'name' => 'Estoque', 'description' => 'Gerenciar estoque de produtos.', 'input_description' => 'Operacoes de estoque.', 'output_description' => 'Estado final.', 'difficulty' => 'medium', 'tags' => json_encode(['simulacao', 'obi', 'nivel1'])]),
            array_merge($base, ['code' => 'OBI23_SUBSEQ', 'name' => 'Subsequencia', 'description' => 'Encontrar subsequencias em arrays.', 'input_description' => 'Array de numeros.', 'output_description' => 'Maior subsequencia.', 'difficulty' => 'medium', 'tags' => json_encode(['dp', 'obi', 'nivel1'])]),
            array_merge($base, ['code' => 'OBI23_CONTAS', 'name' => 'Contas a Pagar', 'description' => 'Gerenciar contas a pagar com prazos.', 'input_description' => 'Lista de contas.', 'output_description' => 'Ordem de pagamento.', 'difficulty' => 'medium', 'tags' => json_encode(['ordenacao', 'guloso', 'obi', 'nivel2'])]),
            array_merge($base, ['code' => 'OBI23_LEILAO', 'name' => 'Leilao', 'description' => 'Simulacao de um leilao.', 'input_description' => 'Lances dos participantes.', 'output_description' => 'Vencedor.', 'difficulty' => 'medium', 'tags' => json_encode(['simulacao', 'obi', 'nivel2'])]),
            array_merge($base, ['code' => 'OBI23_TOUPEIRA', 'name' => 'Sr. Toupeira', 'description' => 'Jogo da toupeira - whack-a-mole.', 'input_description' => 'Posicoes e tempos.', 'output_description' => 'Pontuacao maxima.', 'difficulty' => 'hard', 'tags' => json_encode(['dp', 'obi', 'senior'])]),
        ];
    }

    protected function getOBI2022(): array
    {
        $base = [
            'source' => 'OBI 2022',
            'source_url' => 'https://olimpiada.ic.unicamp.br/passadas/OBI2022/',
            'time_limit' => 1,
            'memory_limit' => 256,
            'is_active' => true,
        ];

        return [
            array_merge($base, ['code' => 'OBI22_CINEMA', 'name' => 'Cinema', 'description' => 'Distribuir pessoas em assentos de cinema.', 'input_description' => 'Numero de pessoas e assentos.', 'output_description' => 'Distribuicao otima.', 'difficulty' => 'easy', 'tags' => json_encode(['matematica', 'obi', 'junior'])]),
            array_merge($base, ['code' => 'OBI22_HOTEL', 'name' => 'Hotel', 'description' => 'Reservas de quartos de hotel.', 'input_description' => 'Lista de reservas.', 'output_description' => 'Numero de quartos necessarios.', 'difficulty' => 'easy', 'tags' => json_encode(['ordenacao', 'obi', 'junior'])]),
            array_merge($base, ['code' => 'OBI22_QUADMAG', 'name' => 'Quadrado Magico', 'description' => 'Verificar se uma matriz e um quadrado magico.', 'input_description' => 'Matriz NxN.', 'output_description' => 'Sim ou Nao.', 'difficulty' => 'medium', 'tags' => json_encode(['matrizes', 'obi', 'junior'])]),
            array_merge($base, ['code' => 'OBI22_SHOW', 'name' => 'Show', 'description' => 'Organizar apresentacoes em um show.', 'input_description' => 'Lista de apresentacoes.', 'output_description' => 'Ordem otima.', 'difficulty' => 'medium', 'tags' => json_encode(['ordenacao', 'obi', 'nivel1'])]),
            array_merge($base, ['code' => 'OBI22_BOMBOM', 'name' => 'Bombom', 'description' => 'Distribuir bombons entre criancas.', 'input_description' => 'Quantidade de bombons e criancas.', 'output_description' => 'Distribuicao justa.', 'difficulty' => 'medium', 'tags' => json_encode(['matematica', 'obi', 'nivel2'])]),
            array_merge($base, ['code' => 'OBI22_MAIORVAL', 'name' => 'Maior Valor', 'description' => 'Encontrar o maior valor em uma sequencia com operacoes.', 'input_description' => 'Sequencia e operacoes.', 'output_description' => 'Maior valor possivel.', 'difficulty' => 'medium', 'tags' => json_encode(['dp', 'obi', 'nivel2'])]),
            array_merge($base, ['code' => 'OBI22_CHUVA', 'name' => 'Chuva', 'description' => 'Calcular a quantidade de agua retida apos chuva.', 'input_description' => 'Alturas das barras.', 'output_description' => 'Volume de agua.', 'difficulty' => 'hard', 'tags' => json_encode(['dp', 'two pointers', 'obi', 'senior'])]),
        ];
    }

    protected function getOBI2021(): array
    {
        $base = [
            'source' => 'OBI 2021',
            'source_url' => 'https://olimpiada.ic.unicamp.br/passadas/OBI2021/',
            'time_limit' => 1,
            'memory_limit' => 256,
            'is_active' => true,
        ];

        return [
            array_merge($base, ['code' => 'OBI21_IDADECAM', 'name' => 'Idade de Camila', 'description' => 'Descobrir a idade de Camila baseado em pistas.', 'input_description' => 'Pistas sobre a idade.', 'output_description' => 'Idade de Camila.', 'difficulty' => 'easy', 'tags' => json_encode(['matematica', 'obi', 'junior'])]),
            array_merge($base, ['code' => 'OBI21_PLANOINT', 'name' => 'Plano de Internet', 'description' => 'Calcular o melhor plano de internet.', 'input_description' => 'Opcoes de planos.', 'output_description' => 'Melhor plano.', 'difficulty' => 'easy', 'tags' => json_encode(['matematica', 'obi', 'junior'])]),
            array_merge($base, ['code' => 'OBI21_TENIS', 'name' => 'Torneio de Tenis', 'description' => 'Simular um torneio de tenis.', 'input_description' => 'Participantes e resultados.', 'output_description' => 'Vencedor.', 'difficulty' => 'easy', 'tags' => json_encode(['simulacao', 'obi', 'junior'])]),
            array_merge($base, ['code' => 'OBI21_TEMPORESP', 'name' => 'Tempo de Resposta', 'description' => 'Calcular tempo medio de resposta.', 'input_description' => 'Lista de tempos.', 'output_description' => 'Tempo medio.', 'difficulty' => 'easy', 'tags' => json_encode(['matematica', 'obi', 'nivel1'])]),
            array_merge($base, ['code' => 'OBI21_ZEROCANC', 'name' => 'Zero para Cancelar', 'description' => 'Cancelar elementos para obter zero.', 'input_description' => 'Lista de numeros.', 'output_description' => 'Minimo de cancelamentos.', 'difficulty' => 'medium', 'tags' => json_encode(['matematica', 'obi', 'nivel1'])]),
            array_merge($base, ['code' => 'OBI21_CIFRA', 'name' => 'Cifra da Nlogonia', 'description' => 'Decodificar uma cifra especial.', 'input_description' => 'Texto cifrado.', 'output_description' => 'Texto decifrado.', 'difficulty' => 'medium', 'tags' => json_encode(['strings', 'obi', 'nivel2'])]),
            array_merge($base, ['code' => 'OBI21_BARALHO', 'name' => 'Baralho', 'description' => 'Problema envolvendo manipulacao de cartas.', 'input_description' => 'Cartas do baralho.', 'output_description' => 'Resultado da operacao.', 'difficulty' => 'hard', 'tags' => json_encode(['simulacao', 'obi', 'senior'])]),
        ];
    }

    protected function getOBI2019(): array
    {
        $base = [
            'source' => 'OBI 2019',
            'source_url' => 'https://olimpiada.ic.unicamp.br/passadas/OBI2019/',
            'time_limit' => 1,
            'memory_limit' => 256,
            'is_active' => true,
        ];

        return [
            array_merge($base, ['code' => 'OBI19_DOMINO', 'name' => 'Domino', 'description' => 'Calcular pontuacao em jogo de domino.', 'input_description' => 'Pecas do domino.', 'output_description' => 'Pontuacao total.', 'difficulty' => 'easy', 'tags' => json_encode(['matematica', 'obi', 'junior'])]),
            array_merge($base, ['code' => 'OBI19_MONICA', 'name' => 'A Idade de Dona Monica', 'description' => 'Descobrir a idade de Dona Monica.', 'input_description' => 'Pistas sobre a idade.', 'output_description' => 'Idade.', 'difficulty' => 'easy', 'tags' => json_encode(['matematica', 'obi', 'junior'])]),
            array_merge($base, ['code' => 'OBI19_SEQSEC', 'name' => 'Sequencia Secreta', 'description' => 'Descobrir uma sequencia secreta.', 'input_description' => 'Dicas da sequencia.', 'output_description' => 'Sequencia.', 'difficulty' => 'easy', 'tags' => json_encode(['logica', 'obi', 'junior'])]),
            array_merge($base, ['code' => 'OBI19_NOTA', 'name' => 'Nota', 'description' => 'Calcular nota final do aluno.', 'input_description' => 'Notas parciais.', 'output_description' => 'Nota final.', 'difficulty' => 'easy', 'tags' => json_encode(['matematica', 'obi', 'nivel1'])]),
            array_merge($base, ['code' => 'OBI19_JOGODOM', 'name' => 'Jogo de Dominos', 'description' => 'Simular jogo completo de domino.', 'input_description' => 'Pecas e jogadas.', 'output_description' => 'Vencedor.', 'difficulty' => 'medium', 'tags' => json_encode(['simulacao', 'obi', 'nivel1'])]),
            array_merge($base, ['code' => 'OBI19_DISTAMIG', 'name' => 'Distancia entre Amigos', 'description' => 'Calcular distancia entre amigos em uma rede.', 'input_description' => 'Rede de amizades.', 'output_description' => 'Distancia.', 'difficulty' => 'medium', 'tags' => json_encode(['grafos', 'bfs', 'obi', 'nivel1'])]),
            array_merge($base, ['code' => 'OBI19_CALCADA', 'name' => 'Calcada Imperial', 'description' => 'Construir calcada com padroes.', 'input_description' => 'Especificacoes.', 'output_description' => 'Padrao construido.', 'difficulty' => 'medium', 'tags' => json_encode(['implementacao', 'obi', 'nivel2'])]),
            array_merge($base, ['code' => 'OBI19_SOMA', 'name' => 'Soma', 'description' => 'Encontrar subconjuntos com determinada soma.', 'input_description' => 'Conjunto e soma alvo.', 'output_description' => 'Quantidade de subconjuntos.', 'difficulty' => 'hard', 'tags' => json_encode(['dp', 'obi', 'nivel2'])]),
            array_merge($base, ['code' => 'OBI19_CHUVA2', 'name' => 'Chuva', 'description' => 'Problema de acumulacao de agua.', 'input_description' => 'Terreno.', 'output_description' => 'Agua acumulada.', 'difficulty' => 'hard', 'tags' => json_encode(['dp', 'obi', 'senior'])]),
        ];
    }

    protected function getOBI2018(): array
    {
        $base = [
            'source' => 'OBI 2018',
            'source_url' => 'https://olimpiada.ic.unicamp.br/passadas/OBI2018/',
            'time_limit' => 1,
            'memory_limit' => 256,
            'is_active' => true,
        ];

        return [
            array_merge($base, ['code' => 'OBI18_BASQUETE', 'name' => 'Basquete de Robos', 'description' => 'Simular jogo de basquete com robos.', 'input_description' => 'Jogadas dos robos.', 'output_description' => 'Placar final.', 'difficulty' => 'easy', 'tags' => json_encode(['simulacao', 'obi', 'junior'])]),
            array_merge($base, ['code' => 'OBI18_ALBUMCOPA', 'name' => 'Album da Copa', 'description' => 'Completar album de figurinhas da Copa.', 'input_description' => 'Figurinhas coletadas.', 'output_description' => 'Figurinhas faltantes.', 'difficulty' => 'easy', 'tags' => json_encode(['contagem', 'obi', 'junior'])]),
            array_merge($base, ['code' => 'OBI18_XADREZ', 'name' => 'Xadrez', 'description' => 'Verificar jogadas validas de xadrez.', 'input_description' => 'Posicoes das pecas.', 'output_description' => 'Jogadas validas.', 'difficulty' => 'medium', 'tags' => json_encode(['simulacao', 'obi', 'nivel1'])]),
            array_merge($base, ['code' => 'OBI18_ESCADINHA', 'name' => 'Escadinha', 'description' => 'Construir escadinha com blocos.', 'input_description' => 'Blocos disponiveis.', 'output_description' => 'Altura maxima.', 'difficulty' => 'easy', 'tags' => json_encode(['matematica', 'obi', 'nivel1'])]),
            array_merge($base, ['code' => 'OBI18_PIRAMIDE', 'name' => 'Piramide', 'description' => 'Construir piramide com blocos.', 'input_description' => 'Blocos disponiveis.', 'output_description' => 'Piramide construida.', 'difficulty' => 'medium', 'tags' => json_encode(['matematica', 'obi', 'nivel1'])]),
            array_merge($base, ['code' => 'OBI18_PISO', 'name' => 'Piso da Escola', 'description' => 'Calcular area do piso da escola.', 'input_description' => 'Dimensoes.', 'output_description' => 'Area total.', 'difficulty' => 'medium', 'tags' => json_encode(['geometria', 'obi', 'nivel2'])]),
            array_merge($base, ['code' => 'OBI18_FIGURINHAS', 'name' => 'Figurinhas da Copa', 'description' => 'Trocar figurinhas da Copa.', 'input_description' => 'Figurinhas de cada pessoa.', 'output_description' => 'Trocas possiveis.', 'difficulty' => 'medium', 'tags' => json_encode(['contagem', 'obi', 'nivel2'])]),
            array_merge($base, ['code' => 'OBI18_CAMARA', 'name' => 'Camara de Compensacao', 'description' => 'Simular camara de compensacao bancaria.', 'input_description' => 'Transacoes.', 'output_description' => 'Saldos finais.', 'difficulty' => 'hard', 'tags' => json_encode(['simulacao', 'obi', 'nivel2'])]),
            array_merge($base, ['code' => 'OBI18_ILHAS', 'name' => 'Ilhas', 'description' => 'Contar ilhas em um mapa.', 'input_description' => 'Mapa do arquipelago.', 'output_description' => 'Numero de ilhas.', 'difficulty' => 'hard', 'tags' => json_encode(['grafos', 'dfs', 'obi', 'senior'])]),
        ];
    }

    protected function getOBI2017(): array
    {
        $base = [
            'source' => 'OBI 2017',
            'source_url' => 'https://olimpiada.ic.unicamp.br/passadas/OBI2017/',
            'time_limit' => 1,
            'memory_limit' => 256,
            'is_active' => true,
        ];

        return [
            array_merge($base, ['code' => 'OBI17_BONDINHO', 'name' => 'Bondinho', 'description' => 'Calcular viagens de bondinho.', 'input_description' => 'Passageiros e capacidade.', 'output_description' => 'Numero de viagens.', 'difficulty' => 'easy', 'tags' => json_encode(['matematica', 'obi', 'junior'])]),
            array_merge($base, ['code' => 'OBI17_DRONE', 'name' => 'Drone de Entrega', 'description' => 'Otimizar rotas de drone de entrega.', 'input_description' => 'Pontos de entrega.', 'output_description' => 'Rota otima.', 'difficulty' => 'easy', 'tags' => json_encode(['matematica', 'obi', 'junior'])]),
            array_merge($base, ['code' => 'OBI17_TELEFERICO', 'name' => 'Teleferico', 'description' => 'Simular funcionamento de teleferico.', 'input_description' => 'Passageiros e tempos.', 'output_description' => 'Tempo total.', 'difficulty' => 'easy', 'tags' => json_encode(['simulacao', 'obi', 'nivel1'])]),
            array_merge($base, ['code' => 'OBI17_COFRE', 'name' => 'Cofre', 'description' => 'Abrir cofre com combinacao.', 'input_description' => 'Tentativas de combinacao.', 'output_description' => 'Combinacao correta.', 'difficulty' => 'medium', 'tags' => json_encode(['forca bruta', 'obi', 'nivel1'])]),
            array_merge($base, ['code' => 'OBI17_GAME10', 'name' => 'Game-10', 'description' => 'Jogo de cartas Game-10.', 'input_description' => 'Cartas dos jogadores.', 'output_description' => 'Vencedor.', 'difficulty' => 'medium', 'tags' => json_encode(['simulacao', 'obi', 'nivel2'])]),
            array_merge($base, ['code' => 'OBI17_CHEFE', 'name' => 'Chefe', 'description' => 'Hierarquia de chefes na empresa.', 'input_description' => 'Estrutura da empresa.', 'output_description' => 'Chefe de cada funcionario.', 'difficulty' => 'medium', 'tags' => json_encode(['arvores', 'obi', 'nivel2'])]),
            array_merge($base, ['code' => 'OBI17_BOTAS', 'name' => 'Botas Trocadas', 'description' => 'Trocar botas entre pessoas.', 'input_description' => 'Botas de cada pessoa.', 'output_description' => 'Trocas necessarias.', 'difficulty' => 'hard', 'tags' => json_encode(['grafos', 'obi', 'nivel2'])]),
            array_merge($base, ['code' => 'OBI17_PALINDROMO', 'name' => 'Palindromo', 'description' => 'Transformar string em palindromo.', 'input_description' => 'String original.', 'output_description' => 'Minimo de operacoes.', 'difficulty' => 'hard', 'tags' => json_encode(['strings', 'dp', 'obi', 'universitario'])]),
        ];
    }

    protected function getOBI2016(): array
    {
        $base = [
            'source' => 'OBI 2016',
            'source_url' => 'https://olimpiada.ic.unicamp.br/passadas/OBI2016/',
            'time_limit' => 1,
            'memory_limit' => 256,
            'is_active' => true,
        ];

        return [
            array_merge($base, ['code' => 'OBI16_PARIMPAR', 'name' => 'Jogo de Par ou Impar', 'description' => 'Simular jogo de par ou impar.', 'input_description' => 'Jogadas.', 'output_description' => 'Vencedor.', 'difficulty' => 'easy', 'tags' => json_encode(['matematica', 'obi', 'junior'])]),
            array_merge($base, ['code' => 'OBI16_LAMPADAS', 'name' => 'Lampadas', 'description' => 'Controlar estado de lampadas.', 'input_description' => 'Estados e operacoes.', 'output_description' => 'Estado final.', 'difficulty' => 'easy', 'tags' => json_encode(['simulacao', 'obi', 'junior'])]),
            array_merge($base, ['code' => 'OBI16_MORANGOS', 'name' => 'Plantacao de Morangos', 'description' => 'Calcular producao de morangos.', 'input_description' => 'Dados da plantacao.', 'output_description' => 'Producao total.', 'difficulty' => 'easy', 'tags' => json_encode(['matematica', 'obi', 'junior'])]),
            array_merge($base, ['code' => 'OBI16_CLUBE5', 'name' => 'Clube dos Cinco', 'description' => 'Formar grupos de cinco pessoas.', 'input_description' => 'Lista de pessoas.', 'output_description' => 'Grupos formados.', 'difficulty' => 'medium', 'tags' => json_encode(['combinatoria', 'obi', 'nivel1'])]),
            array_merge($base, ['code' => 'OBI16_TACOS', 'name' => 'Tacos de Bilhar', 'description' => 'Organizar tacos de bilhar.', 'input_description' => 'Tacos disponiveis.', 'output_description' => 'Organizacao otima.', 'difficulty' => 'medium', 'tags' => json_encode(['ordenacao', 'obi', 'nivel1'])]),
            array_merge($base, ['code' => 'OBI16_CHAVES', 'name' => 'Chaves', 'description' => 'Encontrar a chave correta.', 'input_description' => 'Chaves e fechaduras.', 'output_description' => 'Correspondencias.', 'difficulty' => 'medium', 'tags' => json_encode(['busca', 'obi', 'nivel2'])]),
            array_merge($base, ['code' => 'OBI16_CHUVA3', 'name' => 'Chuva', 'description' => 'Calcular agua acumulada.', 'input_description' => 'Terreno.', 'output_description' => 'Volume de agua.', 'difficulty' => 'hard', 'tags' => json_encode(['dp', 'obi', 'nivel2'])]),
            array_merge($base, ['code' => 'OBI16_LAMPHOTEL', 'name' => 'Lampadas do Hotel', 'description' => 'Controlar lampadas em hotel.', 'input_description' => 'Quartos e interruptores.', 'output_description' => 'Estado final.', 'difficulty' => 'hard', 'tags' => json_encode(['simulacao', 'xor', 'obi', 'nivel2'])]),
            array_merge($base, ['code' => 'OBI16_SANDUICHE', 'name' => 'Sanduiche', 'description' => 'Montar sanduiche com ingredientes.', 'input_description' => 'Ingredientes disponiveis.', 'output_description' => 'Melhor sanduiche.', 'difficulty' => 'medium', 'tags' => json_encode(['guloso', 'obi', 'nivel2'])]),
            array_merge($base, ['code' => 'OBI16_SACI', 'name' => 'Toca do Saci', 'description' => 'Encontrar a toca do Saci.', 'input_description' => 'Mapa da floresta.', 'output_description' => 'Localizacao.', 'difficulty' => 'hard', 'tags' => json_encode(['grafos', 'obi', 'nivel2'])]),
            array_merge($base, ['code' => 'OBI16_DIRECAO', 'name' => 'Direcao', 'description' => 'Determinar direcao de movimento.', 'input_description' => 'Sequencia de movimentos.', 'output_description' => 'Direcao final.', 'difficulty' => 'hard', 'tags' => json_encode(['simulacao', 'obi', 'universitario'])]),
            array_merge($base, ['code' => 'OBI16_AVENIDA', 'name' => 'Nova Avenida', 'description' => 'Planejar construcao de nova avenida.', 'input_description' => 'Mapa da cidade.', 'output_description' => 'Melhor rota.', 'difficulty' => 'hard', 'tags' => json_encode(['grafos', 'obi', 'universitario'])]),
        ];
    }

    protected function getOBI2015(): array
    {
        $base = [
            'source' => 'OBI 2015',
            'source_url' => 'https://olimpiada.ic.unicamp.br/passadas/OBI2015/',
            'time_limit' => 1,
            'memory_limit' => 256,
            'is_active' => true,
        ];

        return [
            array_merge($base, ['code' => 'OBI15_MOBILE', 'name' => 'Mobile', 'description' => 'Calcular equilibrio de mobile.', 'input_description' => 'Pesos e distancias.', 'output_description' => 'Mobile equilibrado.', 'difficulty' => 'easy', 'tags' => json_encode(['matematica', 'obi', 'junior'])]),
            array_merge($base, ['code' => 'OBI15_FITA', 'name' => 'Fita Colorida', 'description' => 'Cortar fita colorida de forma otima.', 'input_description' => 'Comprimento e cores.', 'output_description' => 'Cortes realizados.', 'difficulty' => 'easy', 'tags' => json_encode(['strings', 'obi', 'junior'])]),
            array_merge($base, ['code' => 'OBI15_PREMIO', 'name' => 'Premio do Milhao', 'description' => 'Simular programa de premios.', 'input_description' => 'Respostas do participante.', 'output_description' => 'Premio ganho.', 'difficulty' => 'easy', 'tags' => json_encode(['simulacao', 'obi', 'junior'])]),
            array_merge($base, ['code' => 'OBI15_LINHAS', 'name' => 'Linhas', 'description' => 'Contar intersecoes de linhas.', 'input_description' => 'Coordenadas das linhas.', 'output_description' => 'Numero de intersecoes.', 'difficulty' => 'medium', 'tags' => json_encode(['geometria', 'obi', 'nivel1'])]),
            array_merge($base, ['code' => 'OBI15_ARQUIVOS', 'name' => 'Arquivos', 'description' => 'Gerenciar sistema de arquivos.', 'input_description' => 'Operacoes de arquivo.', 'output_description' => 'Estado final.', 'difficulty' => 'medium', 'tags' => json_encode(['simulacao', 'obi', 'nivel1'])]),
            array_merge($base, ['code' => 'OBI15_COBRA', 'name' => 'Cobra Coral', 'description' => 'Identificar cobras corais.', 'input_description' => 'Padrao de cores.', 'output_description' => 'Tipo de cobra.', 'difficulty' => 'medium', 'tags' => json_encode(['strings', 'obi', 'nivel2'])]),
            array_merge($base, ['code' => 'OBI15_QUEBRA', 'name' => 'Quebra-cabeca', 'description' => 'Montar quebra-cabeca.', 'input_description' => 'Pecas disponiveis.', 'output_description' => 'Quebra-cabeca montado.', 'difficulty' => 'hard', 'tags' => json_encode(['backtracking', 'obi', 'nivel2'])]),
            array_merge($base, ['code' => 'OBI15_FAMILIA', 'name' => 'Familia Real', 'description' => 'Arvore genealogica real.', 'input_description' => 'Relacoes familiares.', 'output_description' => 'Herdeiro ao trono.', 'difficulty' => 'hard', 'tags' => json_encode(['arvores', 'obi', 'nivel2'])]),
            array_merge($base, ['code' => 'OBI15_CAIXINHA', 'name' => 'Caixinha', 'description' => 'Otimizar uso de caixinhas.', 'input_description' => 'Objetos e caixas.', 'output_description' => 'Distribuicao otima.', 'difficulty' => 'hard', 'tags' => json_encode(['dp', 'bin packing', 'obi', 'nivel2'])]),
            array_merge($base, ['code' => 'OBI15_BANCO', 'name' => 'O Banco Inteligente', 'description' => 'Simular banco inteligente.', 'input_description' => 'Operacoes bancarias.', 'output_description' => 'Resultado das operacoes.', 'difficulty' => 'hard', 'tags' => json_encode(['simulacao', 'estruturas', 'obi', 'nivel2'])]),
        ];
    }

    protected function getClassicProblems(): array
    {
        return [
            // Problemas classicos de programacao competitiva
            [
                'code' => 'CLASSIC_FIBONACCI',
                'name' => 'Sequencia de Fibonacci',
                'description' => 'Calcule o N-esimo termo da sequencia de Fibonacci. A sequencia comeca com F(0)=0, F(1)=1, e cada termo seguinte e a soma dos dois anteriores.',
                'input_description' => 'Um inteiro N (0 <= N <= 45).',
                'output_description' => 'O N-esimo termo da sequencia de Fibonacci.',
                'sample_input' => "10",
                'sample_output' => "55",
                'time_limit' => 1,
                'memory_limit' => 256,
                'difficulty' => 'easy',
                'tags' => json_encode(['matematica', 'dp', 'classico']),
                'source' => 'Classico',
                'source_url' => null,
                'is_active' => true,
            ],
            [
                'code' => 'CLASSIC_PRIMES',
                'name' => 'Numeros Primos',
                'description' => 'Dado um numero N, verifique se ele e primo. Um numero primo e divisivel apenas por 1 e por ele mesmo.',
                'input_description' => 'Um inteiro N (2 <= N <= 10^9).',
                'output_description' => 'Imprima "SIM" se N for primo, "NAO" caso contrario.',
                'sample_input' => "17",
                'sample_output' => "SIM",
                'time_limit' => 1,
                'memory_limit' => 256,
                'difficulty' => 'easy',
                'tags' => json_encode(['matematica', 'primos', 'classico']),
                'source' => 'Classico',
                'source_url' => null,
                'is_active' => true,
            ],
            [
                'code' => 'CLASSIC_GCD',
                'name' => 'Maximo Divisor Comum',
                'description' => 'Dados dois numeros A e B, calcule o MDC (Maximo Divisor Comum) entre eles usando o algoritmo de Euclides.',
                'input_description' => 'Dois inteiros A e B (1 <= A, B <= 10^9).',
                'output_description' => 'O MDC de A e B.',
                'sample_input' => "48 18",
                'sample_output' => "6",
                'time_limit' => 1,
                'memory_limit' => 256,
                'difficulty' => 'easy',
                'tags' => json_encode(['matematica', 'euclides', 'classico']),
                'source' => 'Classico',
                'source_url' => null,
                'is_active' => true,
            ],
            [
                'code' => 'CLASSIC_BINSEARCH',
                'name' => 'Busca Binaria',
                'description' => 'Dado um array ordenado de N elementos e um valor X, encontre a posicao de X no array usando busca binaria.',
                'input_description' => 'N, seguido de N inteiros ordenados, seguido do valor X a ser buscado.',
                'output_description' => 'A posicao de X (1-indexado) ou -1 se nao encontrado.',
                'sample_input' => "5\n1 3 5 7 9\n5",
                'sample_output' => "3",
                'time_limit' => 1,
                'memory_limit' => 256,
                'difficulty' => 'easy',
                'tags' => json_encode(['busca', 'binaria', 'classico']),
                'source' => 'Classico',
                'source_url' => null,
                'is_active' => true,
            ],
            [
                'code' => 'CLASSIC_MERGESORT',
                'name' => 'Ordenacao por Intercalacao',
                'description' => 'Ordene um array de N elementos usando o algoritmo Merge Sort.',
                'input_description' => 'N seguido de N inteiros.',
                'output_description' => 'Os N inteiros ordenados.',
                'sample_input' => "5\n3 1 4 1 5",
                'sample_output' => "1 1 3 4 5",
                'time_limit' => 1,
                'memory_limit' => 256,
                'difficulty' => 'medium',
                'tags' => json_encode(['ordenacao', 'divisao conquista', 'classico']),
                'source' => 'Classico',
                'source_url' => null,
                'is_active' => true,
            ],
            [
                'code' => 'CLASSIC_DFS',
                'name' => 'Busca em Profundidade',
                'description' => 'Dado um grafo com N vertices e M arestas, faca uma busca em profundidade (DFS) a partir do vertice 1.',
                'input_description' => 'N e M, seguidos de M pares de vertices representando as arestas.',
                'output_description' => 'Os vertices visitados na ordem da DFS.',
                'sample_input' => "4 4\n1 2\n1 3\n2 4\n3 4",
                'sample_output' => "1 2 4 3",
                'time_limit' => 1,
                'memory_limit' => 256,
                'difficulty' => 'medium',
                'tags' => json_encode(['grafos', 'dfs', 'classico']),
                'source' => 'Classico',
                'source_url' => null,
                'is_active' => true,
            ],
            [
                'code' => 'CLASSIC_BFS',
                'name' => 'Busca em Largura',
                'description' => 'Dado um grafo com N vertices e M arestas, encontre a menor distancia do vertice 1 para todos os outros vertices.',
                'input_description' => 'N e M, seguidos de M pares de vertices representando as arestas.',
                'output_description' => 'N inteiros representando a distancia do vertice 1 para cada vertice.',
                'sample_input' => "4 4\n1 2\n1 3\n2 4\n3 4",
                'sample_output' => "0 1 1 2",
                'time_limit' => 1,
                'memory_limit' => 256,
                'difficulty' => 'medium',
                'tags' => json_encode(['grafos', 'bfs', 'classico']),
                'source' => 'Classico',
                'source_url' => null,
                'is_active' => true,
            ],
            [
                'code' => 'CLASSIC_DIJKSTRA',
                'name' => 'Caminho Minimo - Dijkstra',
                'description' => 'Dado um grafo ponderado com N vertices e M arestas, encontre o menor caminho do vertice 1 ao vertice N.',
                'input_description' => 'N e M, seguidos de M triplas (u, v, w) representando aresta de u para v com peso w.',
                'output_description' => 'A menor distancia de 1 a N, ou -1 se nao houver caminho.',
                'sample_input' => "4 5\n1 2 1\n1 3 4\n2 3 2\n2 4 5\n3 4 1",
                'sample_output' => "4",
                'time_limit' => 1,
                'memory_limit' => 256,
                'difficulty' => 'hard',
                'tags' => json_encode(['grafos', 'dijkstra', 'classico']),
                'source' => 'Classico',
                'source_url' => null,
                'is_active' => true,
            ],
            [
                'code' => 'CLASSIC_KNAPSACK',
                'name' => 'Problema da Mochila',
                'description' => 'Dado uma mochila com capacidade W e N itens, cada um com peso e valor, encontre o valor maximo que pode ser carregado.',
                'input_description' => 'N e W, seguidos de N pares (peso, valor).',
                'output_description' => 'O valor maximo que cabe na mochila.',
                'sample_input' => "3 50\n10 60\n20 100\n30 120",
                'sample_output' => "220",
                'time_limit' => 1,
                'memory_limit' => 256,
                'difficulty' => 'hard',
                'tags' => json_encode(['dp', 'mochila', 'classico']),
                'source' => 'Classico',
                'source_url' => null,
                'is_active' => true,
            ],
            [
                'code' => 'CLASSIC_LCS',
                'name' => 'Maior Subsequencia Comum',
                'description' => 'Dadas duas strings, encontre o comprimento da maior subsequencia comum (LCS).',
                'input_description' => 'Duas strings S1 e S2.',
                'output_description' => 'O comprimento da maior subsequencia comum.',
                'sample_input' => "ABCDGH\nAEDFHR",
                'sample_output' => "3",
                'time_limit' => 1,
                'memory_limit' => 256,
                'difficulty' => 'hard',
                'tags' => json_encode(['dp', 'strings', 'lcs', 'classico']),
                'source' => 'Classico',
                'source_url' => null,
                'is_active' => true,
            ],
            [
                'code' => 'CLASSIC_UNIONFIND',
                'name' => 'Union-Find',
                'description' => 'Implemente a estrutura Union-Find para responder consultas de conectividade em um grafo.',
                'input_description' => 'N vertices, Q operacoes (U a b = unir, Q a b = consultar).',
                'output_description' => 'Para cada consulta, SIM se conectados, NAO caso contrario.',
                'sample_input' => "5 5\nU 1 2\nU 3 4\nQ 1 2\nQ 1 3\nU 2 3",
                'sample_output' => "SIM\nNAO",
                'time_limit' => 1,
                'memory_limit' => 256,
                'difficulty' => 'hard',
                'tags' => json_encode(['estruturas', 'union-find', 'classico']),
                'source' => 'Classico',
                'source_url' => null,
                'is_active' => true,
            ],
        ];
    }
}
