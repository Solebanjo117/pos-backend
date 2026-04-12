#!/bin/bash

echo "════════════════════════════════════════════════════════════════"
echo "  Verificación de Setup - Fase 1 de Documentación HermeX POS"
echo "════════════════════════════════════════════════════════════════"
echo ""

# Colores para output
GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Contador de verificaciones
CHECKS_PASSED=0
CHECKS_FAILED=0

# Función para verificar
check_file() {
    if [ -f "$1" ]; then
        echo -e "${GREEN}✓${NC} $1"
        ((CHECKS_PASSED++))
    else
        echo -e "${RED}✗${NC} $1"
        ((CHECKS_FAILED++))
    fi
}

check_dir() {
    if [ -d "$1" ]; then
        echo -e "${GREEN}✓${NC} Carpeta: $1"
        ((CHECKS_PASSED++))
    else
        echo -e "${RED}✗${NC} Carpeta: $1"
        ((CHECKS_FAILED++))
    fi
}

echo "📂 Verificando Estructura de Carpetas..."
echo ""

check_dir ".vitepress"
check_dir "docs"
check_dir "docs/manual-usuario"
check_dir "docs/manual-usuario/roles"
check_dir "docs/manual-usuario/modulos"
check_dir "docs/manual-usuario/flujos"
check_dir "docs/manual-tecnico"
check_dir "docs/manual-tecnico/arquitectura"
check_dir "docs/manual-tecnico/api"
check_dir "docs/manual-tecnico/database"
check_dir "docs/manual-tecnico/frontend"
check_dir "docs/manual-tecnico/development"
check_dir "docs/manual-tecnico/security"
check_dir "docs/operaciones"
check_dir "docs/operaciones/deployment"
check_dir "docs/operaciones/administracion"
check_dir "docs/operaciones/performance"
check_dir "docs/operaciones/security"
check_dir "docs/procesos-negocio"
check_dir "public"
check_dir "public/screenshots"
check_dir "public/diagrams"
check_dir "public/videos"

echo ""
echo "📄 Verificando Archivos de Configuración..."
echo ""

check_file "package.json"
check_file ".vitepress/config.ts"
check_file ".gitignore"

echo ""
echo "📝 Verificando Documentos de Contenido..."
echo ""

check_file "docs/index.md"
check_file "docs/manual-usuario/index.md"
check_file "docs/manual-tecnico/index.md"
check_file "docs/operaciones/index.md"
check_file "docs/procesos-negocio/index.md"

echo ""
echo "📚 Verificando Guías de Desarrollo..."
echo ""

check_file "README.md"
check_file "GETTING_STARTED.md"
check_file "SCRIBE_SETUP.md"
check_file "FASE_1_COMPLETADA.md"

echo ""
echo "════════════════════════════════════════════════════════════════"
echo ""

if command -v node &> /dev/null; then
    echo -e "${GREEN}✓${NC} Node.js instalado: $(node --version)"
    ((CHECKS_PASSED++))
else
    echo -e "${RED}✗${NC} Node.js NO instalado"
    ((CHECKS_FAILED++))
fi

if command -v npm &> /dev/null; then
    echo -e "${GREEN}✓${NC} npm instalado: $(npm --version)"
    ((CHECKS_PASSED++))
else
    echo -e "${RED}✗${NC} npm NO instalado"
    ((CHECKS_FAILED++))
fi

if [ -d "node_modules" ]; then
    echo -e "${GREEN}✓${NC} node_modules existe (dependencias instaladas)"
    ((CHECKS_PASSED++))
else
    echo -e "${YELLOW}⚠${NC} node_modules NO existe (ejecuta: npm install)"
fi

echo ""
echo "════════════════════════════════════════════════════════════════"
echo ""
echo -e "Resultados: ${GREEN}${CHECKS_PASSED} ✓${NC} ${RED}${CHECKS_FAILED} ✗${NC}"
echo ""

if [ $CHECKS_FAILED -eq 0 ]; then
    echo -e "${GREEN}✨ SETUP VERIFICADO CORRECTAMENTE ✨${NC}"
    echo ""
    echo "Próximos pasos:"
    echo "  1. npm install (si no ejecutaste antes)"
    echo "  2. npm run docs:dev"
    echo "  3. Abre: http://localhost:5173/"
    echo ""
else
    echo -e "${RED}⚠ VERIFICAR LAS CARPETAS/ARCHIVOS FALTANTES${NC}"
fi

echo "════════════════════════════════════════════════════════════════"
