<!--
  Six cases, pas douze : une liste trop longue se coche sans être lue, ce qui
  reproduit exactement le défaut qu'elle prétend éviter. Chacune vient d'un
  défaut réellement survenu sur ce projet.

  Trois états, et trois seulement :

    [x] fait
    [ ] applicable, et pas fait — se justifie dans « Ce que je n'ai pas fait »
    ~~barré~~ — ne s'applique pas à CETTE PR, suivi du motif sur la même ligne

  Un barré sans motif est interdit. Barrer, c'est affirmer qu'une consigne du
  projet ne concerne pas ce changement — jamais qu'on l'abandonne. Cette
  affirmation se justifie, sinon la case la plus exigeante devient la plus facile
  à faire disparaître.

  Ne pas confondre les deux derniers états. « Aucune clé de traduction touchée »
  se barre : la consigne ne s'applique pas. « Je n'ai pas écrit le test »
  se laisse vide : elle s'applique, et il manque quelque chose.
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

<!-- La moitié honnête, et la contrepartie obligatoire de toute case laissée
     vide ci-dessus : chacune se justifie ici, en prose, où l'argument peut être
     contesté.

     Y figurent aussi ce qui reste ouvert, ce qui a été repoussé dans un ticket,
     et ce qui n'est vérifié par rien.

     Une PR sans cette section affirme une complétude que presque aucune PR
     n'a. -->
