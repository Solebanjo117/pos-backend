import { Product } from '../types/catalog';

export const mockProducts: Product[] = [
  {
    id: '1',
    code: 'BRK-001',
    name: 'Pastillas de Freno Delanteras',
    model: 'Premium Ceramic',
    category: 'Frenos',
    image: 'https://images.unsplash.com/photo-1486262715619-67b85e0b08d3?w=400',
    measures: '150mm x 60mm x 18mm',
    description: 'Pastillas de freno cerámicas de alto rendimiento',
    compatibility: [
      { brand: 'Toyota', model: 'Corolla', series: 'E210', year: 2020 },
      { brand: 'Toyota', model: 'Corolla', series: 'E210', year: 2021 },
      { brand: 'Honda', model: 'Civic', series: 'FC', year: 2019 },
    ],
    brands: [
      { brand: 'Brembo', price: 12500, stock: 15 },
      { brand: 'ATE', price: 9800, stock: 8 },
      { brand: 'Bosch', price: 11200, stock: 22 },
    ]
  },
  {
    id: '2',
    code: 'FLT-045',
    name: 'Filtro de Aceite',
    model: 'Standard',
    category: 'Filtros',
    image: 'https://images.unsplash.com/photo-1625047509248-ec889cbff17f?w=400',
    measures: 'Diámetro: 76mm, Altura: 85mm',
    description: 'Filtro de aceite de alta eficiencia',
    compatibility: [
      { brand: 'Ford', model: 'Focus', series: 'Mk4', year: 2019 },
      { brand: 'Ford', model: 'Fiesta', series: 'Mk8', year: 2020 },
      { brand: 'Chevrolet', model: 'Cruze', series: 'J400', year: 2018 },
    ],
    brands: [
      { brand: 'Mann Filter', price: 1850, stock: 45 },
      { brand: 'Bosch', price: 1650, stock: 0 },
      { brand: 'Fram', price: 1420, stock: 35 },
    ]
  },
  {
    id: '3',
    code: 'SPK-112',
    name: 'Bujías de Platino',
    model: 'Long Life',
    category: 'Motor',
    image: 'https://images.unsplash.com/photo-1580273916550-e323be2ae537?w=400',
    measures: 'Rosca: M14x1.25, Gap: 1.0mm',
    description: 'Bujías de platino de larga duración',
    compatibility: [
      { brand: 'Nissan', model: 'Sentra', series: 'B18', year: 2020 },
      { brand: 'Nissan', model: 'Versa', series: 'N18', year: 2021 },
      { brand: 'Mazda', model: '3', series: 'BP', year: 2019 },
    ],
    brands: [
      { brand: 'NGK', price: 3200, stock: 28 },
      { brand: 'Denso', price: 3450, stock: 19 },
      { brand: 'Bosch', price: 2980, stock: 41 },
    ]
  },
  {
    id: '4',
    code: 'SUS-078',
    name: 'Amortiguadores Traseros',
    model: 'Gas Pressurized',
    category: 'Suspensión',
    image: 'https://images.unsplash.com/photo-1619642751034-765dfdf7c58e?w=400',
    measures: 'Longitud: 520mm, Diámetro: 51mm',
    description: 'Amortiguadores presurizados con gas para mejor confort',
    compatibility: [
      { brand: 'Volkswagen', model: 'Golf', series: 'Mk7', year: 2018 },
      { brand: 'Volkswagen', model: 'Jetta', series: 'A7', year: 2019 },
      { brand: 'Audi', model: 'A3', series: '8V', year: 2017 },
    ],
    brands: [
      { brand: 'KYB', price: 18500, stock: 12 },
      { brand: 'Monroe', price: 16800, stock: 7 },
      { brand: 'Bilstein', price: 22300, stock: 4 },
    ]
  },
  {
    id: '5',
    code: 'BLT-234',
    name: 'Correa de Distribución',
    model: 'Reinforced',
    category: 'Motor',
    image: 'https://images.unsplash.com/photo-1492144534655-ae79c964c9d7?w=400',
    measures: 'Longitud: 1200mm, Ancho: 25mm',
    description: 'Correa reforzada de alta resistencia',
    compatibility: [
      { brand: 'Hyundai', model: 'Elantra', series: 'CN7', year: 2021 },
      { brand: 'Kia', model: 'Forte', series: 'BD', year: 2020 },
      { brand: 'Kia', model: 'Cerato', series: 'BD', year: 2019 },
    ],
    brands: [
      { brand: 'Gates', price: 8950, stock: 18 },
      { brand: 'Dayco', price: 8200, stock: 25 },
      { brand: 'ContiTech', price: 9100, stock: 0 },
    ]
  },
  {
    id: '6',
    code: 'BAT-567',
    name: 'Batería 12V 60Ah',
    model: 'Calcium Plus',
    category: 'Eléctrico',
    image: 'https://images.unsplash.com/photo-1593941707882-a5bba14938c7?w=400',
    measures: '242mm x 175mm x 190mm',
    description: 'Batería de calcio libre de mantenimiento',
    compatibility: [
      { brand: 'Toyota', model: 'Yaris', series: 'XP210', year: 2020 },
      { brand: 'Honda', model: 'Fit', series: 'GR', year: 2021 },
      { brand: 'Nissan', model: 'March', series: 'K13', year: 2019 },
    ],
    brands: [
      { brand: 'Bosch', price: 14500, stock: 32 },
      { brand: 'Varta', price: 13800, stock: 28 },
      { brand: 'AC Delco', price: 12900, stock: 19 },
    ]
  },
  {
    id: '7',
    code: 'DSC-890',
    name: 'Discos de Freno',
    model: 'Ventilated',
    category: 'Frenos',
    image: 'https://images.unsplash.com/photo-1449426468159-d96dbf08f19f?w=400',
    measures: 'Diámetro: 300mm, Espesor: 28mm',
    description: 'Discos ventilados de alto rendimiento',
    compatibility: [
      { brand: 'BMW', model: '320i', series: 'F30', year: 2018 },
      { brand: 'BMW', model: '318i', series: 'F30', year: 2019 },
      { brand: 'Mercedes-Benz', model: 'C200', series: 'W205', year: 2017 },
    ],
    brands: [
      { brand: 'Brembo', price: 28500, stock: 8 },
      { brand: 'ATE', price: 24300, stock: 14 },
      { brand: 'Zimmermann', price: 26100, stock: 6 },
    ]
  },
  {
    id: '8',
    code: 'FLT-156',
    name: 'Filtro de Aire',
    model: 'High Flow',
    category: 'Filtros',
    image: 'https://images.unsplash.com/photo-1621939514649-280e2ee25f60?w=400',
    measures: '280mm x 220mm x 45mm',
    description: 'Filtro de aire de alto flujo',
    compatibility: [
      { brand: 'Mazda', model: 'CX-5', series: 'KF', year: 2020 },
      { brand: 'Mazda', model: 'CX-30', series: 'DM', year: 2021 },
      { brand: 'Subaru', model: 'XV', series: 'GT', year: 2019 },
    ],
    brands: [
      { brand: 'K&N', price: 4850, stock: 22 },
      { brand: 'Mann Filter', price: 3200, stock: 35 },
      { brand: 'Bosch', price: 3500, stock: 41 },
    ]
  },
];

export const categories = [
  'Frenos',
  'Filtros',
  'Motor',
  'Suspensión',
  'Eléctrico',
  'Transmisión',
  'Refrigeración',
  'Escape'
];

export const brands = [
  'Brembo',
  'Bosch',
  'Mann Filter',
  'NGK',
  'Denso',
  'KYB',
  'Monroe',
  'Gates',
  'Dayco',
  'Varta',
  'ATE',
  'Fram',
  'Bilstein',
  'ContiTech',
  'AC Delco',
  'K&N',
  'Zimmermann'
];

export const vehicleBrands = ['Toyota', 'Honda', 'Nissan', 'Ford', 'Chevrolet', 'Mazda', 'Volkswagen', 'Hyundai', 'Kia', 'BMW', 'Mercedes-Benz', 'Audi', 'Subaru'];
