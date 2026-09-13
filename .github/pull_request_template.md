<!--
  Six cases, pas douze : une liste trop longue se coche sans être lue, ce qui
  reproduit exactement le défaut qu'elle prétend éviter. Chacune vient d'un
  défaut réellement survenu sur ce projet.

  Une case qui ne s'applique pas se barre (~~texte~~) plutôt que de se cocher.
-->

## Ce que fait cette PR

<!-- Le problème d'abord, la solution ensuite. Ce qui a été écarté compte
     autant que ce qui a été retenu. -->

## Définition de fini

- [ ] **Un test par critère d'acceptation** du ticket — pas un test qui les survole tous
- [ ] **Chaque garde-fou prouvé par mutation** : le saboter fait tomber un test, et le bon
- [ ] **Composants créés documentés** dans `CLAUDE.md` — vérifié par `grep`, pas de mémoire
- [ ] **Locales régénérées** si une clé de traduction a changé, et le cache avec
- [ ] **`npm run check:full` : 7/7**
- [ ] **Décisions écartées consignées** dans le code ou le ticket, avec leur motif

## Ce que je n'ai pas fait

<!-- La moitié honnête. Ce qui reste ouvert, ce qui a été repoussé dans un
     ticket, ce qui n'est vérifié par rien.

     Une PR sans cette section affirme une complétude que presque aucune PR
     n'a. -->
