# Deploy-guide — Engros Bestillingsportal → Simply.com

Når dette er sat op, deployer du sådan her i fremtiden:

> **Ret kode → `git commit` → `git push` → online på ~30 sekunder.**

Du kan også rette filer direkte på github.com og trykke "Commit" — så deployer den automatisk.

---

## Engangsopsætning (gøres én gang)

### 1. Opret en GitHub-konto og et nyt repo
1. Opret gratis konto på <https://github.com> (hvis du ikke har en).
2. Klik **New repository**.
   - Navn: fx `engros-bestilling`
   - Vælg **Private** (vigtigt — så koden ikke er offentlig).
   - Opret **uden** readme/gitignore (vi har dem allerede).

### 2. Skub projektet op til GitHub (køres i projektmappen)
```bash
git init
git add .
git commit -m "Første version"
git branch -M main
git remote add origin https://github.com/<DIT-BRUGERNAVN>/engros-bestilling.git
git push -u origin main
```
> `.gitignore` sørger automatisk for, at `bc_config.json`, `engros.db`, cache og logs **ikke** kommer med — kun selve koden.

### 3. Læg dine Simply FTP-oplysninger ind som "secrets" i GitHub
Find oplysningerne i Simply's kontrolpanel under **FTP** (eller FTP-konti).
Gå derefter til dit GitHub-repo → **Settings → Secrets and variables → Actions → New repository secret**, og opret disse fire:

| Secret-navn | Værdi | Eksempel |
|---|---|---|
| `FTP_SERVER` | Simply's FTP-servernavn | `ssh.simply.com` el. det Simply oplyser |
| `FTP_USERNAME` | Dit FTP-brugernavn | (fra Simply) |
| `FTP_PASSWORD` | Dit FTP-kodeord | (fra Simply) |
| `FTP_TARGET_DIR` | Mappen subdomænet peger på — **skal slutte med `/`** | `/public_html/engros/` |

> Hemmelighederne skrives kun ind i GitHub — aldrig i koden. Kun GitHub's robot kan læse dem.

### 4. Første deploy
- Når du har pushet (trin 2) og lagt secrets ind (trin 3), så gå til repoets **Actions**-fane.
- Kør "Deploy til Simply.com" (starter selv ved push, eller tryk **Run workflow**).
- Grønt flueben = filerne er online. Rødt = klik ind og se fejlen (typisk forkert FTP-login eller mappe).

---

## Sådan retter du noget bagefter
**Lokalt:**
```bash
# ret dine filer ...
git add .
git commit -m "Beskriv ændringen"
git push
```
**Eller direkte på github.com:** åbn filen → blyant-ikon → ret → **Commit changes**. Begge dele deployer automatisk.

---

## Vigtigt at vide
- **Databasen er sikker.** Deploy rører ALDRIG `engros.db`, `bc_config.json`, cache eller logs (se `exclude`-listen i `.github/workflows/deploy.yml`). Rigtige ordrer og brugere overskrives aldrig.
- **Engangsting der IKKE styres af deploy** (gøres manuelt på serveren én gang):
  - `bc_config.json` lægges over webmappen.
  - PHP-version sættes til 8.x i Simply.
  - HTTPS slås til.
- **Hvis FTPS ikke vil forbinde:** Simply bruger måske kun almindelig FTP eller SFTP. Sig til — så skifter vi `protocol: ftps` til `ftp` i workflow-filen, eller bruger en SFTP-baseret deploy i stedet.
