# SISTEMA-WEB-PORTABLE-DE-AGENDAMIENTO-CLINICO-E-HISTORIAS-CLINICAS
# 🏥 Sistema Web Portable de Agendamiento Clínico e Historias Clínicas (Zero-Configuration)

¡Bienvenido al repositorio oficial del **Trabajo Práctico Experimental N.° 4** para la asignatura de **Interacción Humano-Computador (IHC)** en la **Universidad Estatal de Milagro (UNEMI)**! 🚀

Este proyecto consiste en una aplicación web médica modular y portable diseñada bajo principios avanzados de **Diseño Centrado en el Usuario (DCU)** y ergonomía cognitiva. El sistema optimiza el flujo administrativo y la gestión del expediente clínico eliminando por completo la fricción técnica de instalación.

---

## 🌟 Características Clave

### 📂 Portabilidad Absoluta (*Zero-Configuration*)
El sistema es completamente autónomo y ejecutable desde cualquier unidad de almacenamiento local o extraíble (Pendrive USB) en sistemas operativos Windows. 
* Incorpora su propio entorno aislado de **PHP embebido**.
* Utiliza el motor relacional *Serverless* **SQLite**, eliminando la necesidad de instalar o configurar servidores globales externos como XAMPP, Laragon o MySQL.

### 🔄 Flujo Condicional Inteligente de Agendamiento (IHC)
Minimiza la carga cognitiva del operador mediante un algoritmo interactivo en caliente:
* **Paciente Existente:** Al ingresar una Cédula de Identidad (CI) registrada, el sistema enlaza la información de manera inmediata y agenda directamente.
* **Paciente Nuevo (Flujo de Pausa):** Si el ciudadano no consta en la persistencia local, el sistema **pausa inmediatamente el agendamiento** y despliega dinámicamente el módulo "Ficha de Paciente Nuevo". Tras guardar sus datos civiles, **despausa y procesa automáticamente la cita médica en suspenso** en un solo clic.

### ⏳ Cola de Atención Automatizada FIFO
El **Dashboard Administrativo** filtra exclusivamente las consultas en estatus *Pendiente* y las organiza de manera estricta mediante la lógica estructural de colas **FIFO (First In, First Out)**, ordenándolas cronológicamente por fecha y hora más antiguas para garantizar salas de espera transparentes y eficientes.

### 🩺 Historia Clínica Adaptativa por Especialidad
El expediente médico (`?route=pacientes`) cuenta con una interfaz asíncrona dividida:
* **Barrera de Seguridad:** Resalta mediante etiquetas cromáticas de alta visibilidad (`.badge-allergy`) las condiciones de alergias críticas del paciente.
* **Formulario Reactivo:** Al procesar la consulta, el formulario muta según la especialidad de la cita: inyecta campos para hallazgos electrocardiográficos (ECG) y riesgos en *Cardiología*, o percentiles, peso y talla en *Pediatría*.
* **Inmutabilidad Legal:** Al guardar, la cita muta a *Cumplida* y bloquea las cajas de texto para auditorías médicas transparentes.

### 🖨️ Aislamiento Estético de Impresión
Implementa reglas multimedia avanzadas a través de CSS3 (`@media print`). Al activar el comando de impresión (Ctrl + P) sobre un dictamen médico terminado, el software oculta automáticamente barras de navegación, botones y fondos, aislando únicamente la receta y las observaciones clínicas para una salida digital (PDF) o física pulcra.

---

## 🛠️ Arquitectura Tecnológica

* **Controlador Maestro:** `index.php` (Arquitectura monolítica modular, manejo seguro de sesiones y enrutamiento dinámico).
* **Seguridad de Capa de Datos:** Consultas preparadas a través de **PDO** para anular cualquier vector de ataque por inyección SQL (*SQL Injection*).
* **Motor de Persistencia:** SQLite (`citas_medicas.db`) con activación imperativa de integridad referencial mediante `PRAGMA foreign_keys = ON;`.
* **Inicializador por Lotes:** `EJECUTAR_SISTEMA.bat` (Automatiza la lectura de rutas relativas, levanta el servidor web de PHP en `127.0.0.1:8080` y lanza el navegador predeterminado). 

---


1. **Clonar el repositorio** o descargar el archivo ZIP en tu máquina local:
   ```bash
   git clone [https://github.com/tu-usuario/tu-repositorio.git](https://github.com/tu-usuario/tu-repositorio.git)
