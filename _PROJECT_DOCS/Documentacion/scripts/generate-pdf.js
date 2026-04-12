#!/usr/bin/env node

/**
 * Script para generar PDFs de las diferentes secciones de documentación
 *
 * Uso:
 *   node scripts/generate-pdf.js manual-usuario
 *   node scripts/generate-pdf.js manual-tecnico
 *   node scripts/generate-pdf.js operaciones
 *   node scripts/generate-pdf.js procesos-negocio
 */

const fs = require('fs');
const path = require('path');
const puppeteer = require('puppeteer');

// Configuración
const sections = {
  'manual-usuario': {
    title: 'Manual de Usuario - HermeX POS',
    url: 'http://localhost:5173/manual-usuario/',
    filename: 'HermeX-Manual-Usuario.pdf',
    description: 'Guías operativas para usuarios finales'
  },
  'manual-tecnico': {
    title: 'Manual Técnico - HermeX POS',
    url: 'http://localhost:5173/manual-tecnico/',
    filename: 'HermeX-Manual-Tecnico.pdf',
    description: 'Documentación técnica para desarrolladores'
  },
  'operaciones': {
    title: 'Manual de Operaciones - HermeX POS',
    url: 'http://localhost:5173/operaciones/',
    filename: 'HermeX-Manual-Operaciones.pdf',
    description: 'Guías de despliegue y operaciones'
  },
  'procesos-negocio': {
    title: 'Procesos de Negocio - HermeX POS',
    url: 'http://localhost:5173/procesos-negocio/',
    filename: 'HermeX-Procesos-Negocio.pdf',
    description: 'Documentación de flujos de negocio'
  }
};

async function generatePDF(sectionKey) {
  const section = sections[sectionKey];

  if (!section) {
    console.error(`❌ Sección no encontrada: ${sectionKey}`);
    console.log(`Secciones disponibles: ${Object.keys(sections).join(', ')}`);
    process.exit(1);
  }

  try {
    console.log(`\n📄 Generando PDF: ${section.title}`);
    console.log(`📍 URL: ${section.url}`);
    console.log(`💾 Archivo: ${section.filename}\n`);

    // Crear directorio si no existe
    const outputDir = path.join(__dirname, '..', 'dist', 'pdfs');
    if (!fs.existsSync(outputDir)) {
      fs.mkdirSync(outputDir, { recursive: true });
    }

    // Iniciar navegador
    const browser = await puppeteer.launch({
      headless: true,
      args: ['--no-sandbox', '--disable-setuid-sandbox']
    });

    const page = await browser.newPage();

    // Configurar tamaño de página
    await page.setViewport({
      width: 1280,
      height: 960
    });

    // Navegar a la página
    console.log('⏳ Cargando página...');
    await page.goto(section.url, {
      waitUntil: 'networkidle2',
      timeout: 60000
    });

    // Esperar a que Mermaid diagrama se carguen
    await page.waitForTimeout(3000);

    // Generar PDF
    console.log('🖨️  Generando PDF...');
    const pdfPath = path.join(outputDir, section.filename);

    await page.pdf({
      path: pdfPath,
      format: 'A4',
      margin: {
        top: '20mm',
        right: '15mm',
        bottom: '20mm',
        left: '15mm'
      },
      printBackground: true,
      headerTemplate: `
        <style>
          body { margin: 0; padding: 0; font-size: 10px; }
          .header { width: 100%; text-align: center; color: #666; }
        </style>
        <div class="header">HermeX POS - ${section.title}</div>
      `,
      footerTemplate: `
        <style>
          body { margin: 0; padding: 0; font-size: 10px; }
          .footer { width: 100%; text-align: right; color: #999; padding-right: 15mm; }
        </style>
        <div class="footer">Página <span class="pageNumber"></span></div>
      `,
      displayHeaderFooter: true
    });

    // Cerrar navegador
    await browser.close();

    // Obtener tamaño del archivo
    const stats = fs.statSync(pdfPath);
    const sizeMB = (stats.size / 1024 / 1024).toFixed(2);

    console.log(`\n✅ PDF generado exitosamente!`);
    console.log(`📁 Ubicación: ${pdfPath}`);
    console.log(`📊 Tamaño: ${sizeMB} MB\n`);

  } catch (error) {
    console.error(`❌ Error generando PDF: ${error.message}`);
    process.exit(1);
  }
}

// Obtener sección del argumento o mostrar ayuda
const section = process.argv[2];

if (!section) {
  console.log('📚 Generador de PDFs - HermeX POS Documentación\n');
  console.log('Uso: node scripts/generate-pdf.js <seccion>\n');
  console.log('Secciones disponibles:');
  Object.entries(sections).forEach(([key, value]) => {
    console.log(`  • ${key}`);
    console.log(`    └─ ${value.description}\n`);
  });
  process.exit(0);
}

// Generar PDF
generatePDF(section);
