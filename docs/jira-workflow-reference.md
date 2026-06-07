# JIRA — Référence workflow Lumina

Projet **LUMN** ("Lumina") — Company-managed, instance `cardascia-it.atlassian.net`.
Cloud ID : `ce1e33ab-e0ad-42ee-90b9-1712e6204310`

Workflow strict à 8 statuts. Référence des IDs pour les automatisations
(GitHub Actions → JIRA REST API, JIRA Automation).

## Statuts

| Statut | Status ID | Catégorie |
|--------|-----------|-----------|
| Backlog | 10038 | À faire |
| Prêt | 10004 | À faire |
| En cours | 10039 | En cours |
| En review | 10040 | En cours |
| PR approuvée | 10041 | En cours |
| En déploiement | 10042 | En cours |
| En production | 10043 | Terminé |
| Bloqué | 10044 | En cours |

## Transitions

| ID | Nom | De → Vers | Déclencheur cible |
|----|-----|-----------|-------------------|
| 2 | Préparer | Backlog → Prêt | Manuel (ou JIRA Automation : crée l'issue GitHub) |
| 3 | Démarrer | Prêt → En cours | Manuel / création de branche |
| 4 | Ouvrir PR | En cours → En review | GitHub Actions — `pull_request: opened` |
| 5 | PR approuvée | En review → PR approuvée | GitHub Actions — PR mergée + CI verte |
| 6 | Déployer | PR approuvée → En déploiement | GitHub Actions — job deploy déclenché |
| 7 | Mettre en production | En déploiement → En production | GitHub Actions — smoke tests OK (ferme l'issue) |
| 8 | Bloquer | (actifs) → Bloqué | Manuel |
| 9 | Débloquer (replanifier) | Bloqué → Prêt | Manuel |
| 10 | Débloquer (reprendre) | Bloqué → En cours | Manuel |
| 11 | Débloquer (review) | Bloqué → En review | Manuel |
| 12 | Abandonner | En cours → Backlog | Manuel |
| 13 | Demander corrections | En review → En cours | GitHub Actions — changes requested (optionnel) |
| 14 | Rejeter (CI) | PR approuvée → En cours | GitHub Actions — CI rouge post-merge |
| 15 | Rollback | En déploiement → En cours | GitHub Actions — échec déploiement / smoke tests |

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
