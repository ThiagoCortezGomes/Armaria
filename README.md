# Armaria

Sistema web em PHP para controle de armamento, coletes balísticos, munições, usuários, cautelas, devoluções, comprovantes e auditoria. O projeto foi estruturado para uso interno, com foco em rastreabilidade operacional, separação por perfis e emissão de comprovantes em PDF.

## Visão geral

O Armaria centraliza o fluxo de guarda e movimentação de itens controlados. O sistema permite:

- cadastrar e gerenciar armas, coletes, munições e usuários;
- registrar cautelas e devoluções com validação por senha do policial;
- controlar disponibilidade, itens cautelados e itens inativos;
- emitir comprovantes individuais e comprovantes combinados;
- consultar histórico, relatórios e logs de usuários;
- aplicar permissões distintas para `ADMIN`, `ARMEIRO` e `POLICIAL`.

## Principais funcionalidades

### Controle de acesso por perfil

- `ADMIN`
  - cadastra e gerencia usuários;
  - cadastra e gerencia armas, coletes e munições;
  - consulta relatórios, histórico e logs;
  - ajusta dados administrativos.
- `ARMEIRO`
  - realiza cautelas e devoluções;
  - consulta armas, coletes, munições e relatórios;
  - não executa cadastros administrativos restritos ao `ADMIN`.
- `POLICIAL`
  - consulta suas cautelas ativas;
  - consulta seus comprovantes;
  - altera a própria senha;
  - não acessa cadastros nem movimentações administrativas.

### Cautelas

- cautela unificada de armas, munições, carregadores e colete em uma única tela;
- suporte a múltiplas armas para o mesmo policial na mesma operação;
- suporte a cautela apenas de munição e/ou carregador, sem arma;
- bloqueio de arma já cautelada;
- bloqueio de arma `INATIVA`;
- bloqueio de colete `INATIVO` ou já cautelado;
- regra de 1 colete ativo por policial;
- exclusão automática de munições de treinamento da lista de cautela;
- validação de senha do policial antes da confirmação;
- emissão de comprovante individual e comprovante combinado.

### Devoluções

- devolução unificada de arma e colete em uma única tela;
- validação por senha do policial;
- atualização da interface sem recarregar a página;
- reposição de munição ao estoque quando vinculada à devolução de arma;
- emissão de comprovante individual e comprovante combinado.

### Cadastros e consultas

- armas:
  - cadastro;
  - consulta por tipo, modelo, calibre ou série;
  - edição de modelo;
  - inativação e reativação, com bloqueio se houver cautela ativa.
- coletes:
  - cadastro;
  - consulta;
  - atualização de validade;
  - inativação conforme regras do sistema.
- munições:
  - cadastro por calibre e tipo;
  - incremento de estoque;
  - ajuste manual de quantidade;
  - relatório específico de munições.
- usuários:
  - cadastro;
  - busca de policial;
  - listagem;
  - exclusão controlada;
  - atualização conjunta de e-mail e posto/graduação;
  - numeração da listagem com coluna `Ord.`.

### Relatórios e auditoria

- relatório de armas em PDF;
- relatório de armas em Excel;
- relatório de coletes em PDF;
- relatório de munições;
- histórico por arma;
- logs de usuários com filtros por ação, usuário e período;
- eventos de segurança para falhas de senha.

## Tecnologias utilizadas

- PHP 8
- MySQL / MariaDB
- PDO
- HTML5
- CSS3
- JavaScript vanilla
- FPDF para geração de PDF
- XAMPP como ambiente local recomendado

## Estrutura do projeto

```text
Armaria/
├─ config/
│  ├─ auth.php
│  ├─ audit.php
│  ├─ combined_receipt.php
│  ├─ constants.php
│  ├─ db.php
│  ├─ login.php
│  └─ security.php
├─ database/
│  └─ migrations/
├─ public/
│  ├─ assets/
│  ├─ partials/
│  ├─ assign.php
│  ├─ return.php
│  ├─ weapons.php
│  ├─ vests.php
│  ├─ munitions.php
│  ├─ users.php
│  ├─ reports.php
│  ├─ report_pdf.php
│  ├─ report_excel.php
│  ├─ report_vests_pdf.php
│  ├─ report_munitions.php
│  ├─ history.php
│  ├─ meus_comprovantes.php
│  ├─ minha_cautela.php
│  ├─ user_logs.php
│  ├─ login.php
│  └─ index.php
├─ scripts/
│  └─ run_migrations.php
├─ templates/
│  └─ modelo_importacao_policiais.csv
├─ tests/
│  └─ password_gate_flows_test.php
└─ vendor/
   └─ fpdf/
```

## Estrutura funcional

### `config/`

Contém autenticação, constantes de perfis e status, conexão com banco, segurança, auditoria e suporte a comprovantes combinados.

### `public/`

Contém as páginas acessíveis pela aplicação, organizadas em módulos de cadastro, movimentação, consulta, relatórios e autenticação.

### `database/migrations/`

Contém migrations incrementais para complementar a estrutura do banco.

### `scripts/`

Contém utilitários de manutenção, como a execução automática das migrations.

### `vendor/fpdf/`

Biblioteca FPDF já incluída no projeto para geração de PDF, sem necessidade de instalação via Composer.

## Requisitos

- XAMPP com Apache e MySQL ativos
- PHP 8.1+ recomendado
- MySQL ou MariaDB
- Navegador moderno

## Instalação

### 1. Copiar o projeto para o XAMPP

Coloque a pasta do projeto em:

```text
C:\xampp\htdocs\Armaria
```

### 2. Criar o banco de dados

No phpMyAdmin ou no MySQL, crie o banco:

```sql
CREATE DATABASE armaria CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

### 3. Configurar a conexão

Copie o arquivo de exemplo e preencha com suas credenciais:

```bash
cp config/db.example.php config/db.php
```

O arquivo `config/db.php` **não está no repositório** (contém credenciais). Por padrão, para XAMPP local:

```php
$DB_HOST = "127.0.0.1";
$DB_NAME = "armaria";
$DB_USER = "root";
$DB_PASS = "";
```

### 4. Criar a estrutura base do banco

Importante: o repositório possui migrations incrementais, mas não inclui um dump SQL completo com todas as tabelas base do sistema. Isso significa que o ambiente deve partir de uma base já existente do projeto ou de um dump inicial mantido fora do repositório.

As migrations disponíveis adicionam e ajustam estruturas complementares, como:

- eventos de segurança;
- índices;
- tokens de redefinição de senha;
- campos de munição nas cautelas;
- suporte a movimentação sem arma em `movements`.

### 5. Executar as migrations

No terminal, dentro da raiz do projeto:

```powershell
php scripts\run_migrations.php
```

### 6. Abrir no navegador

```text
http://localhost/Armaria/public/
```

## Uso

### Fluxo básico

1. acessar `login.php`;
2. autenticar com um usuário válido;
3. entrar no dashboard conforme o perfil;
4. usar o menu lateral para movimentação, cadastros e consultas.

### Cautela

O módulo de cautela permite:

- selecionar o policial;
- informar a senha do policial;
- adicionar uma ou mais linhas de cautela;
- escolher arma, munição e quantidade;
- informar carregadores quando aplicável;
- adicionar colete na mesma operação;
- confirmar a cautela e gerar comprovante.

### Devolução

O módulo de devolução permite:

- selecionar arma e/ou colete cautelado;
- validar a senha do policial;
- concluir a devolução;
- atualizar a lista de cautelas ativas sem refresh completo;
- emitir comprovante correspondente.

## Segurança

O sistema possui mecanismos internos de proteção:

- controle de sessão com timeout por inatividade;
- proteção CSRF para requisições sensíveis;
- validação de senha do policial em operações de cautela e devolução;
- limite de tentativas inválidas de senha;
- registro de eventos de segurança;
- trilha de auditoria para ações importantes do sistema.

## Regras de negócio implementadas

- `ADMIN` cadastra e gerencia usuários, armas, coletes e munições;
- `ARMEIRO` movimenta cautelas e devoluções;
- `POLICIAL` consulta seus dados e comprovantes;
- arma já cautelada não pode ser cautelada novamente;
- arma ou colete inativo não aparece nas listas de cautela;
- cada policial pode ter no máximo 1 colete ativo;
- espingarda calibre 12 não exige carregador;
- munição de treinamento não entra no fluxo de cautela operacional.

## Relatórios disponíveis

- armas cauteladas e disponíveis em PDF;
- armamento em Excel;
- coletes em PDF;
- munições por calibre e situação de estoque;
- histórico de movimentação por arma;
- logs de usuários.

## Comprovantes

O sistema gera:

- comprovante individual de movimentação de arma;
- comprovante individual de movimentação de colete;
- comprovante combinado quando a operação reúne múltiplos itens.

Os comprovantes ficam disponíveis para consulta pelo policial e pela administração, respeitando o perfil logado.

## Testes

O repositório possui atualmente o arquivo:

- [tests/password_gate_flows_test.php](c:\xampp\htdocs\Armaria\tests\password_gate_flows_test.php)

Não há, neste momento, uma suíte de testes automatizados ampla cobrindo todos os módulos.

## Melhorias futuras recomendadas

- incluir um dump SQL inicial completo do banco;
- ampliar cobertura de testes automatizados;
- documentar credenciais iniciais de ambiente de desenvolvimento;
- separar configurações por ambiente;
- adicionar importadores estruturados para armas, coletes e usuários via planilha.

## Licença

Uso interno. Ajuste esta seção conforme a política da organização responsável pelo sistema.

---

*Desenvolvido por [Thiago Cortez Gomes](https://github.com/ThiagoCortezGomes)*
