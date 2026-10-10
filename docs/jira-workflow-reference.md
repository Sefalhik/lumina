# JIRA — Référence workflow Lumina

Projet **LUMN** ("Lumina") — Company-managed, instance `cardascia-it.atlassian.net`.
Cloud ID : `ce1e33ab-e0ad-42ee-90b9-1712e6204310`

Workflow strict à 9 statuts. Référence des IDs pour les automatisations
(GitHub Actions → JIRA REST API, JIRA Automation).

## Statuts

| Statut | Status ID | Catégorie |
|--------|-----------|-----------|
| Backlog | 10038 | À faire |
| Prêt | 10004 | À faire |
| En cours | 10039 | En cours |
| En review | 10040 | En cours |
| PR approuvée | 10041 | En cours |
| En préproduction | 10042 | En cours |
| En production | 10043 | Terminé |
| Bloqué | 10044 | En cours |
| Annulé | 10077 | Terminé |

## Transitions

| ID | Nom | De → Vers | Déclencheur cible |
|----|-----|-----------|-------------------|
| 2 | Préparer | Backlog → Prêt | Manuel (ou JIRA Automation : crée l'issue GitHub) |
| 3 | Démarrer | Prêt → En cours | Manuel / création de branche |
| 4 | Ouvrir PR | En cours → En review | GitHub Actions — `pull_request: opened` |
| 5 | PR approuvée | En review → PR approuvée | GitHub Actions — PR mergée + CI verte |
| 6 | Déployer | PR approuvée → En préproduction | GitHub Actions — job deploy déclenché |
| 7 | Mettre en production | En préproduction → En production | GitHub Actions — smoke tests OK (ferme l'issue) |
| 8 | Bloquer | (actifs) → Bloqué | Manuel |
| 9 | Débloquer (replanifier) | Bloqué → Prêt | Manuel |
| 10 | Débloquer (reprendre) | Bloqué → En cours | Manuel |
| 11 | Débloquer (review) | Bloqué → En review | Manuel |
| 12 | Abandonner | En cours → Backlog | Manuel |
| 13 | Demander corrections | En review → En cours | GitHub Actions — changes requested (optionnel) |
| 14 | Rejeter (CI) | PR approuvée → En cours | GitHub Actions — CI rouge post-merge |
| 15 | Rollback | En préproduction → En cours | GitHub Actions — échec déploiement / smoke tests |
| 16 | Annulé | **globale** (tous statuts) → Annulé | Manuel |
| 17 | Réactivation | Annulé → Backlog | Manuel |

## Appel REST type

```bash
curl -s -X POST \
  -u "$JIRA_EMAIL:$JIRA_API_TOKEN" \
  -H "Content-Type: application/json" \
  "https://cardascia-it.atlassian.net/rest/api/3/issue/$KEY/transitions" \
  -d '{"transition":{"id":"4"}}'
```

Les transitions `Bloquer` (8) et ses sorties (9, 10, 11) ne sont valides que depuis/vers
les états actifs — voir le workflow. `Bloquer` n'est pas joignable depuis `Backlog`
ni `En production`.

## « En préproduction » — renommé le 2026-09-20

Le statut 10042 s'appelait `En déploiement`. Il désignait pourtant, depuis sa création, *« passé la
préproduction, pas encore en production, en attente de validation »* — un séjour qui dure le temps
qu'une décision humaine prenne, pas le temps d'un déploiement.

**Un statut de workflow répond à « où est ce code ? », pas à « que fait le robot en ce moment ? ».**
Un nom de lieu reste vrai tant que le code y est ; un nom d'action ment dès que l'action est finie,
et ment deux fois quand elle a échoué. `En déploiement` nommait une activité de deux minutes pour
décrire un état qui dure des jours.

C'est aussi ce qui a clos la question des statuts intermédiaires — « déploiement préprod à faire »,
« en cours », et les mêmes pour la production. Trois arguments les ont écartés :

* **La valeur d'un statut est proportionnelle au temps qu'on y passe.** Quatre statuts pour deux
  activités de deux minutes, c'est du bruit dans le tableau.
* **Chaque statut a besoin d'une entrée *et* d'une sortie câblées.** Sinon il devient le nouveau
  `PR approuvée`, la seule colonne dont la sortie ne l'était pas — onze tickets y ont stationné.
* **Un statut posé par un job qui plante ensuite est un mensonge que personne ne nettoie.** L'état
  d'une activité en cours a déjà un meilleur domicile : le run GitHub, lié depuis la PR, avec ses
  logs et son temps réel.

`PR approuvée` reste le créneau « fusionnée, pas encore déployée » : étendre `En préproduction` pour
le couvrir ferait mentir le statut dans l'autre sens.

**Le renommage est sans effet sur les automatisations** : `jira-sync.yml` ne référence que des IDs
de transition, l'ID du statut ne change pas, et les noms n'y apparaissent que dans des messages
`echo`. Attention en revanche : dans Jira, un statut est un **objet global** — le renommer le
renomme dans tout projet qui l'utilise.

Les brain dumps de `docs/blog-prep/` gardent l'ancien nom : ce sont des comptes rendus datés, pas
des références, et les réécrire effacerait ce qui était vrai ce jour-là.

## Annulation — ajouté le 2026-09-12

`Annulé` est la seule sortie du workflow autre que `En production`. Avant lui, un ticket obsolète,
doublonné ou absorbé par un autre n'avait nulle part où aller.

Trois choix de conception, tous délibérés :

**Catégorie `Terminé`, pas `En cours`.** Un statut d'annulation rangé en catégorie « En cours »
ferait compter les tickets annulés comme du travail en cours dans tous les rapports, et leur temps
de cycle ne se fermerait jamais. C'est le réglage le plus facile à rater.

**Transition globale.** Un ticket s'annule depuis n'importe quel statut. La transition 16 est donc
globale plutôt que câblée depuis chacun des huit autres. Elle est aussi disponible **depuis
`Annulé` lui-même** — une auto-boucle qui semble inutile et qui ne l'est pas : elle permet de
repasser un ticket annulé avant l'ajout des post-fonctions pour qu'il reçoive enfin sa résolution.

**Une sortie existe.** La transition 17 ramène vers `Backlog`. Sans elle, un statut atteignable en
un clic depuis partout serait définitif, et une erreur de manipulation demanderait une intervention
d'administration.

## Résolutions — le statut ne ferme pas un ticket

Dans Jira, « fermé » est porté par le champ **Résolution**, pas par le statut. Un changement de
statut ne le renseigne **jamais** tout seul.

Un ticket en `Annulé` sans résolution reste « non résolu » : il remonte dans les filtres par défaut
(`resolution = EMPTY`), sa clé ne s'affiche pas barrée, et les rapports fondés sur la date de
résolution l'ignorent. Le statut est vert, la colonne est la bonne, et rien n'est clos.

Les transitions qui terminent un ticket portent donc une **post-fonction** qui renseigne la
résolution :

| Transition | Résolution posée |
|------------|------------------|
| 16 — Annulé | `Won't Do` |
| 7 — Mettre en production | à poser, même principe |

**Une post-fonction ne s'applique qu'aux transitions futures.** Un ticket annulé avant son ajout
reste sans résolution ; il faut le repasser par la transition (d'où l'utilité de l'auto-boucle).

Vérification par l'API plutôt que par l'écran — c'est la couleur verte du statut qui masque
l'absence de résolution :

```bash
curl -s -u "$JIRA_EMAIL:$JIRA_API_TOKEN" \
  "https://cardascia-it.atlassian.net/rest/api/3/issue/$KEY?fields=status,resolution,resolutiondate"
```

## Priorités

Le projet utilise l'échelle Jira standard à cinq niveaux. Elle **complète** le rang du backlog,
elle ne le remplace pas : la priorité partitionne la file, le rang arbitre à l'intérieur d'un
palier. Sans elle, chaque ticket créé exigeait un glisser-déposer manuel pour trouver sa place.

| Priorité | ID | Sens dans ce projet |
|----------|----|---------------------|
| Highest | 1 | en cours, ou prochain à prendre — l'axe prioritaire du moment |
| High | 2 | bloque un autre ticket, ou piège connu qui se redéclenchera |
| Medium | 3 | file normale — **valeur par défaut** |
| Low | 4 | utile, sans urgence ni dépendance |
| Lowest | 5 | dette pure, aucun effet visible |

**La priorité est renseignée à la création**, jamais après coup — y compris dans les brouillons de
tickets soumis à validation, où elle fait partie de l'en-tête au même titre que le type et l'epic.
Un ticket créé sans priorité hérite de `Medium` et se noie dans la file.

Les tickets en `PR approuvée` gardent `Medium` : leur travail est fait, ils attendent un
déploiement, et une priorité n'y décrit plus rien d'actionnable.

### Priorité et liens de blocage ne disent pas la même chose

Une priorité dit « celui-ci compte davantage ». Un lien `Blocks` dit « celui-ci est **impossible**
avant celui-là ». La seconde information est plus forte et se vérifie ; elle n'a pas à être
réencodée en priorité.

## Estimation

Every ticket carries an estimate in **story points**, on the Fibonacci scale. A point does not
measure time: it estimates **effort**, as the amount of work, the complexity, the risk and the
uncertainty make it vary. The estimate is **relative**: it places a ticket among the other tickets
of this project, and compares with no other team's.

The gaps widen with size because precision collapses with it: telling a 1 from a 2 means something,
telling a 9 from a 10 would not. Hesitating at length between two neighbouring values is asking the
scale for a precision nobody has.

| Points | Meaning in this project |
|--------|-------------------------|
| 1 | A trivial, isolated change, such as a label. No business impact, no risk of regression. |
| 2 | A targeted fix in a single place whose effect can reach far: it takes more checking than work. |
| 3 | A limited change spread over several files, of low complexity. |
| 5 | A complex development: several services or front-end components that have to move together, or a large refactoring. |
| 8 | A cross-cutting piece of work: several layers or domains at once, or a real unknown. The largest size started as it is, once it has been checked that it does not split. |
| 13 | The splitting threshold: the ticket is not started as it is. It becomes an epic, split into child tickets. |

**13 is a signal, not a size of work.** A ticket that reaches it almost always spans several
intentions: it is at the wrong level, in the sense of
[One intention, one ticket, one PR, one commit](git-workflow.md#one-intention-one-ticket-one-pr-one-commit).
It becomes an epic, and each child gets its own estimate. An epic carries none: its size is read in
the sum of its children.

**An unknown does not hide inside a large number.** When a ticket cannot be estimated for want of
knowledge, inflating the number does not remove the unknown. The unknown gets a time-boxed
exploration ticket whose deliverable is the answer; the real ticket is estimated afterwards.

**Tickets already estimated are the yardsticks.** A new ticket is compared with two or three of
them, of different sizes, rather than with the definitions of the table alone.

### When and where the estimate is set

**The estimate is set when the ticket is created**, in `customfield_10016`
("Story point estimate"), and belongs in the header of a ticket draft, next to the priority. The
ticket does not repeat the scale: the number lives in the field, its meaning here.

**A ticket started without an estimate gets one at that moment**, before the work begins. Tickets
older than LUMN-73 were not estimated, and are not re-estimated in bulk: those still to do get
theirs one by one, as their turn comes. A finished ticket is never re-estimated — that would measure
work already done.

**The field is missing from the creation metadata of the Tech issue type**, which suggests it does
not exist. It can be written all the same: when the ticket is created, next to the priority —
checked on 2026-10-10 on LUMN-74 — and by editing the ticket afterwards — checked on 2026-10-07 on
LUMN-73. Either way, read the field back.

### What this scale rests on

| Choice made here | What the reference says | Source |
|------------------|-------------------------|--------|
| The Fibonacci scale | The gaps get wider as the numbers get larger, because the larger an item is, the less precisely it can be estimated. | [Story Point Scales](https://www.mountaingoatsoftware.com/agile/story-points/story-point-scales), Mountain Goat Software |
| A point estimates effort | Complexity is one factor among others: the amount of work, the risk and the uncertainty are factors too. | [Story Points Estimate Effort Not Just Complexity](https://www.mountaingoatsoftware.com/blog/its-effort-not-complexity), Mountain Goat Software |
| A relative estimate | The ratios matter, not the numbers themselves: an item is estimated by saying it takes two or three times the effort of another. | [What Are Agile Story Points?](https://www.mountaingoatsoftware.com/blog/what-are-story-points), Mountain Goat Software |
| 13 triggers a split | A large value is not a badge of importance: it warns that the item may be too large, too uncertain or too poorly understood. | [Story Point Scales](https://www.mountaingoatsoftware.com/agile/story-points/story-point-scales), Mountain Goat Software |
| A large ticket is split | Big items are hard to finish; splitting is there to help finish, not to create more backlog items. | [Story Splitting](https://www.mountaingoatsoftware.com/agile/user-stories/story-splitting-how-to-split-user-stories-so-teams-can-finish), Mountain Goat Software |
| The exploration ticket | A spike gains the knowledge that reduces the risk of a technical approach, and makes an estimate more reliable. | [Spikes](https://framework.scaledagile.com/spikes), Scaled Agile Framework |

These references ground the method, not the definition of each value: none of them gives a 3 or a 5
a universal meaning. The definitions in the table are this project's own.

The project departs from them on one point. A popular scale carries on with 20, 40 and 100, to size
items that are still vague in broad strokes. This one stops at 13: beyond it, nothing is estimated,
it is split.
