# Spec: Udstil varens enheder i kvwoo-API'et

Bestillingsportalen skal kunne vise **alle** salgsenheder pr. vare (fx KG **og** 250G),
også enheder uden egen prislinje. Den eneste komplette kilde er BC's tabel
**"Item Unit of Measure" (tabel 5404)** — den udstilles ikke i dag.

Tilføj derfor en ny **read-only API-side** i kvwoo-extensionen (samme mønster som
`WooPriceListLines.Page.al`).

## Krav til endpointet
- **API:** `api/kvwoo/woocommerce/v1.0` (APIPublisher = `kvwoo`, APIGroup = `woocommerce`, APIVersion = `v1.0`)
- **EntitySetName:** `wooItemUnitsOfMeasure`  ← appen kalder præcis dette navn
- **Kildetabel:** `Item Unit of Measure` (5404)
- **Read-only** (ingen insert/modify/delete)
- **Felter (præcise navne — appen læser `itemNo` og `code`):**

| API-felt | BC-felt |
|----------|---------|
| `systemId` | `Rec.SystemId` |
| `itemNo` | `"Item No."` |
| `code` | `"Code"` (enhedskoden, fx `250G`) |
| `qtyPerUnitOfMeasure` | `"Qty. per Unit of Measure"` |

## Eksempel på AL-side
```al
page 50150 "Woo Item Units Of Measure"
{
    PageType = API;
    Caption = 'wooItemUnitsOfMeasure';
    APIPublisher = 'kvwoo';
    APIGroup = 'woocommerce';
    APIVersion = 'v1.0';
    EntityName = 'wooItemUnitOfMeasure';
    EntitySetName = 'wooItemUnitsOfMeasure';
    SourceTable = "Item Unit of Measure";
    DelayedInsert = true;
    Editable = false;
    ODataKeyFields = SystemId;

    layout
    {
        area(Content)
        {
            repeater(Group)
            {
                field(systemId; Rec.SystemId) { }
                field(itemNo; Rec."Item No.") { }
                field(code; Rec."Code") { }
                field(qtyPerUnitOfMeasure; Rec."Qty. per Unit of Measure") { }
            }
        }
    }
}
```

## Test efter publicering
```
GET {base}/api/kvwoo/woocommerce/v1.0/companies({companyId})/wooItemUnitsOfMeasure?$top=5
```
Skal returnere rækker som:
```json
{ "itemNo": "1214", "code": "KG",   "qtyPerUnitOfMeasure": 1 }
{ "itemNo": "1214", "code": "250G", "qtyPerUnitOfMeasure": 0.25 }
```

Når endpointet er live, viser portalen automatisk de komplette enheder pr. vare
(inden for 10 min pga. cache) — ingen yderligere app-ændring nødvendig.
