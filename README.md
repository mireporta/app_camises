# 🏭 Gestió de camises

Aplicació web interna per a la **gestió de recanvis (camises)** associats a màquines de producció, amb control d’inventari per unitat, vida útil i fluxos reals de magatzem i producció.

Pensada per a entorns industrials, amb **traçabilitat completa**, control d’errors i separació clara de responsabilitats entre operaris i encarregats.

---

## 🎯 Objectius del sistema

- Control per **unitat individual (serial)**, no només per SKU
- Garantir **1 unitat per sububicació**
- Tenir control de l'estoc de camises i la vida útil
- Reflectir el flux real:
  - Magatzem → Preparació → Màquina → Producció → Retorn / Baixa
- Facilitar l’ús a planta amb pantalles clares i simples

---

## 🧩 Mòduls principals

### 🖥️ Dashboard (Supervisió)
- KPIs d’inventari
- Estoc sota mínim
- Recanvis amb vida útil <10%
- Recanvis instal·lats a màquina
- **Peticions pendents** amb accions:
  - ✅ Servir (magatzem → preparació)
  - ❌ Anul·lar

---

### 👷 Pantalla d’Operari
- Selecció de màquina
- Petició de recanvis (SKU)
- Visualització de recanvis:
  - Pendents d’entrar
  - Instal·lats a la màquina
- Instal·lació individual de recanvis
- Finalització de producció (consumeix vida útil)
- Retorn de recanvis
- Historial de produccions recents (editable durant temps limitat)

👉 **La vida útil només es resta quan hi ha producció.**

---

### 📦 PDA / Magatzem
- Rebre peticions de camises dels operaris
- Acceptar recanvis del magatzem intermig
- Validació per escaneig de sububicació
- Baixa de recanvis amb motiu
- Dissenyada per ús mòbil

---

### 📊 Inventari
- Estoc per SKU i per unitat
- Vida útil per serial
- Ubicació detallada:
  - Magatzem
  - Preparació
  - Màquina
  - Intermig
- Historial de moviments
- Edició controlada

---

## 🔄 Flux de recanvis

1. Entrada (manual o Excel)
2. Magatzem
3. Petició d’operari
4. Preparació (sense consumir vida)
5. Instal·lació a màquina
6. Producció (consumeix vida útil)
7. Revisió al magatzem intermig
8. Retorn o baixa

---

## 📁 Importació i exportació Excel

- **1 fitxer Excel amb 2 pestanyes**:
  - `items`: SKU, categoria, mínim, actiu, plànol
  - `unitats`: serial, ubicació, sububicació, vida útil, màquina
- Validacions:
  - SKU existent
  - Serial únic
  - 1 unitat per sububicació
  - Coherència ubicació ↔ màquina
- Import protegit amb **contrasenya**
- Export compatible amb reimportació

---

## 🔐 Seguretat

- Contrasenya per importació massiva
- Validacions server-side
- Registre de moviments
- Separació entorn desenvolupament / producció

---

## 🛠️ Tecnologies

- PHP 8+
- MySQL / MariaDB
- Apache
- TailwindCSS
- PhpSpreadsheet
- Composer

---

## 🚀 Desplegament

### Desenvolupament
- XAMPP / Apache + PHP + MySQL
- Composer install
- Base de dades local

### Producció
- Apache + PHP + MySQL al servidor d’empresa
- Import de base de dades
- Configuració de credencials
- Pujar fitxers via Git

---

## 🔄 Actualitzacions

Flux recomanat:
1. Desenvolupar i provar en local
2. Commit a GitHub
3. Pull al servidor d’empresa
4. Migracions SQL si cal
5. Verificació funcional

---

## 📌 Estat del projecte
✔️ Fase de proves 
🔄 Evolutiu segons necessitats

