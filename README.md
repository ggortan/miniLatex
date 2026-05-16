# miniLatex

Aplicação web em **PHP + Bootstrap** para edição de projetos LaTeX no navegador.

## Recursos implementados

- Editor LaTeX com preview lado a lado
- Preview desacoplável em janela separada
- Compilação para PDF via `pdflatex` (e `bibtex` quando disponível)
- Pré-visualização em tempo real (compilação automática com debounce)
- Importação de múltiplos arquivos do projeto (`.tex`, `.bib`, `.sty`, imagens etc.)
- Salvamento do projeto no `localStorage`
- Exportação/importação de projeto em JSON
- Download do PDF compilado

## Como executar

```bash
cd /home/runner/work/miniLatex/miniLatex
php -S 127.0.0.1:8000
```

Abra: `http://127.0.0.1:8000/index.php`

## Observações

- Para compilação completa de LaTeX, o ambiente precisa ter `pdflatex` no PATH.
- Projetos com bibliografia usam `bibtex` automaticamente quando disponível.
- O backend aceita múltiplos arquivos auxiliares (`.bib`, `.sty`, `.cls`, `.bst`, imagens), aproximando o fluxo de ferramentas como Overleaf dentro das limitações locais do projeto.
