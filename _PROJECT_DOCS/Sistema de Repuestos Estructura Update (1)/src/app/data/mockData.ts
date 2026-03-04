import { Chasis, Motor, Carro, Categoria, Producto, ItemVenta } from "../types";

// 1. CHASIS
export const chasisData: Chasis[] = [
  { 
    id: "ch1", 
    marca: "Toyota", 
    modelo: "Yaris", 
    serie: "P90", 
    año: "2006-2013", 
    tipoCarroceria: "Hatchback",
    vins: ["VNK1234567890", "VNK0987654321"] 
  },
  { 
    id: "ch2", 
    marca: "Toyota", 
    modelo: "Corolla", 
    serie: "E140", 
    año: "2006-2013", 
    tipoCarroceria: "Sedan",
    vins: ["JTD1234567890", "JTD0987654321"] 
  },
  { 
    id: "ch3", 
    marca: "Toyota", 
    modelo: "Corolla", 
    serie: "E170", 
    año: "2013-2019", 
    tipoCarroceria: "Sedan",
    vins: ["JTD5555555555", "JTD4444444444"] 
  },
  { 
    id: "ch4", 
    marca: "Toyota", 
    modelo: "RAV4", 
    serie: "A40", 
    año: "2013-2018", 
    tipoCarroceria: "SUV",
    vins: ["JTM1234567890", "JTM0987654321"] 
  },
  { 
    id: "ch5", 
    marca: "Honda", 
    modelo: "Civic", 
    serie: "FB", 
    año: "2012-2015", 
    tipoCarroceria: "Sedan",
    vins: ["1HG1234567890", "1HG0987654321"] 
  },
  { 
    id: "ch6", 
    marca: "Nissan", 
    modelo: "Sentra", 
    serie: "B17", 
    año: "2013-2019", 
    tipoCarroceria: "Sedan",
    vins: ["3N11234567890", "3N10987654321"] 
  },
];

// 2. MOTORES
export const motorData: Motor[] = [
  { id: "m1", codigo: "1NZ-FE", cilindraje: "1.5L", combustible: "Gasolina", transmision: "Manual/Automática" },
  { id: "m2", codigo: "1ZZ-FE", cilindraje: "1.6L", combustible: "Gasolina", transmision: "Manual" },
  { id: "m3", codigo: "2ZR-FE", cilindraje: "1.8L", combustible: "Gasolina", transmision: "CVT" },
  { id: "m4", codigo: "2AR-FE", cilindraje: "2.5L", combustible: "Gasolina", transmision: "Automática" },
  { id: "m5", codigo: "R18Z1", cilindraje: "1.8L", combustible: "Gasolina", transmision: "Manual/CVT" },
  { id: "m6", codigo: "HR16DE", cilindraje: "1.6L", combustible: "Gasolina", transmision: "CVT" },
];

// 3. CARROS (Chasis + Motor)
export const carroData: Carro[] = [
  { id: "c1", chasisId: "ch1", motorId: "m1", nombre: "Toyota Yaris P90 1.5L (2006-2013)" },
  { id: "c2", chasisId: "ch2", motorId: "m2", nombre: "Toyota Corolla E140 1.6L (2006-2013)" },
  { id: "c3", chasisId: "ch3", motorId: "m3", nombre: "Toyota Corolla E170 1.8L (2013-2019)" },
  { id: "c4", chasisId: "ch4", motorId: "m4", nombre: "Toyota RAV4 A40 2.5L (2013-2018)" },
  { id: "c5", chasisId: "ch5", motorId: "m5", nombre: "Honda Civic FB 1.8L (2012-2015)" },
  { id: "c6", chasisId: "ch6", motorId: "m6", nombre: "Nissan Sentra B17 1.6L (2013-2019)" },
];

// 4. CATEGORÍAS
export const categoriaData: Categoria[] = [
  { id: "cat1", nombre: "Filtro de Aire", atributos: ["largo", "alto", "ancho"] },
  { id: "cat2", nombre: "Balinera", atributos: ["diámetro exterior", "diámetro interior", "altura"] },
  { id: "cat3", nombre: "Alternador", atributos: ["pines", "polea", "amperaje", "voltaje"] },
  { id: "cat4", nombre: "Bujía", atributos: ["rosca", "alcance", "abertura"] },
  { id: "cat5", nombre: "Banda de Distribución", atributos: ["largo", "ancho", "dientes"] },
  { id: "cat6", nombre: "Pastillas de Freno", atributos: ["largo", "ancho", "grosor"] },
];

// 5. PRODUCTOS
export const productoData: Producto[] = [
  {
    id: "p1",
    codigo: "17801-21050",
    categoriaId: "cat1",
    aplicaciones: ["c1", "c2", "c3", "c4"],
    atributos: { largo: "100mm", alto: "50mm", ancho: "30mm" },
    descripcion: "Filtro de Aire para Toyota Yaris, Corolla, RAV4",
  },
  {
    id: "p2",
    codigo: "90363-32058",
    categoriaId: "cat2",
    aplicaciones: ["c2", "c3"],
    atributos: { "diámetro exterior": "72mm", "diámetro interior": "37mm", altura: "37mm" },
    descripcion: "Balinera Delantera Toyota Corolla E140/E170",
  },
  {
    id: "p3",
    codigo: "27060-28380",
    categoriaId: "cat3",
    aplicaciones: ["c3", "c4"],
    atributos: { pines: "4", polea: "6 ranuras", amperaje: "130A", voltaje: "12V" },
    descripcion: "Alternador Toyota Corolla E170 / RAV4 A40",
  },
  {
    id: "p4",
    codigo: "90919-01253",
    categoriaId: "cat4",
    aplicaciones: ["c1", "c2"],
    atributos: { rosca: "14mm", alcance: "19mm", abertura: "0.8mm" },
    descripcion: "Bujía Denso para Toyota Yaris / Corolla",
  },
  {
    id: "p5",
    codigo: "13568-28030",
    categoriaId: "cat5",
    aplicaciones: ["c2", "c3"],
    atributos: { largo: "254mm", ancho: "25mm", dientes: "146" },
    descripcion: "Banda de Distribución Toyota Corolla",
  },
  {
    id: "p6",
    codigo: "04465-02280",
    categoriaId: "cat6",
    aplicaciones: ["c3", "c4"],
    atributos: { largo: "131mm", ancho: "55mm", grosor: "17mm" },
    descripcion: "Pastillas de Freno Delanteras Toyota Corolla E170 / RAV4",
  },
  {
    id: "p7",
    codigo: "17220-R1A-A01",
    categoriaId: "cat1",
    aplicaciones: ["c5"],
    atributos: { largo: "95mm", alto: "48mm", ancho: "28mm" },
    descripcion: "Filtro de Aire Honda Civic FB",
  },
  {
    id: "p8",
    codigo: "16546-1HC0A",
    categoriaId: "cat1",
    aplicaciones: ["c6"],
    atributos: { largo: "98mm", alto: "52mm", ancho: "32mm" },
    descripcion: "Filtro de Aire Nissan Sentra B17",
  },
];

// 6. ÍTEMS DE VENTA (Inventario con marcas)
export const itemVentaData: ItemVenta[] = [
  // Producto 1: Filtro de Aire 17801-21050
  { id: "i1", productoId: "p1", marca: "WBS", costo: 1.0, precioVenta: 2.0, stock: 5, proveedor: "asdf" },
  { id: "i2", productoId: "p1", marca: "EDK", costo: 1.5, precioVenta: 2.0, stock: 3, proveedor: "eodh" },
  { id: "i3", productoId: "p1", marca: "JKT", costo: 2.0, precioVenta: 3.0, stock: 5, proveedor: "ywca" },
  { id: "i4", productoId: "p1", marca: "OEM Toyota", costo: 5.0, precioVenta: 8.5, stock: 2, proveedor: "asdf" },
  
  // Producto 2: Balinera 90363-32058
  { id: "i5", productoId: "p2", marca: "NSK", costo: 12.0, precioVenta: 18.0, stock: 4, proveedor: "eodh" },
  { id: "i6", productoId: "p2", marca: "Koyo", costo: 10.5, precioVenta: 16.0, stock: 6, proveedor: "ywca" },
  { id: "i7", productoId: "p2", marca: "FAG", costo: 14.0, precioVenta: 20.0, stock: 2, proveedor: "asdf" },
  
  // Producto 3: Alternador 27060-28380
  { id: "i8", productoId: "p3", marca: "Bosch", costo: 85.0, precioVenta: 120.0, stock: 3, proveedor: "eodh" },
  { id: "i9", productoId: "p3", marca: "Denso", costo: 95.0, precioVenta: 135.0, stock: 2, proveedor: "asdf" },
  { id: "i10", productoId: "p3", marca: "OEM Toyota", costo: 120.0, precioVenta: 165.0, stock: 1, proveedor: "ywca" },
  
  // Producto 4: Bujía 90919-01253
  { id: "i11", productoId: "p4", marca: "Denso", costo: 2.5, precioVenta: 4.0, stock: 20, proveedor: "asdf" },
  { id: "i12", productoId: "p4", marca: "NGK", costo: 2.2, precioVenta: 3.5, stock: 15, proveedor: "eodh" },
  { id: "i13", productoId: "p4", marca: "Bosch", costo: 2.8, precioVenta: 4.5, stock: 12, proveedor: "ywca" },
  
  // Producto 5: Banda de Distribución 13568-28030
  { id: "i14", productoId: "p5", marca: "Gates", costo: 18.0, precioVenta: 28.0, stock: 8, proveedor: "eodh" },
  { id: "i15", productoId: "p5", marca: "Continental", costo: 20.0, precioVenta: 30.0, stock: 5, proveedor: "asdf" },
  { id: "i16", productoId: "p5", marca: "Dayco", costo: 17.5, precioVenta: 27.0, stock: 6, proveedor: "ywca" },
  
  // Producto 6: Pastillas de Freno 04465-02280
  { id: "i17", productoId: "p6", marca: "Akebono", costo: 22.0, precioVenta: 35.0, stock: 10, proveedor: "eodh" },
  { id: "i18", productoId: "p6", marca: "Brembo", costo: 28.0, precioVenta: 42.0, stock: 7, proveedor: "asdf" },
  { id: "i19", productoId: "p6", marca: "Textar", costo: 25.0, precioVenta: 38.0, stock: 5, proveedor: "ywca" },
  
  // Producto 7: Filtro Honda 17220-R1A-A01
  { id: "i20", productoId: "p7", marca: "WBS", costo: 1.2, precioVenta: 2.5, stock: 8, proveedor: "eodh" },
  { id: "i21", productoId: "p7", marca: "OEM Honda", costo: 5.5, precioVenta: 9.0, stock: 3, proveedor: "asdf" },
  
  // Producto 8: Filtro Nissan 16546-1HC0A
  { id: "i22", productoId: "p8", marca: "WBS", costo: 1.1, precioVenta: 2.2, stock: 6, proveedor: "ywca" },
  { id: "i23", productoId: "p8", marca: "OEM Nissan", costo: 5.8, precioVenta: 9.5, stock: 2, proveedor: "eodh" },
];

// Funciones helper para obtener datos relacionados
export const getChasisById = (id: string) => chasisData.find(ch => ch.id === id);
export const getMotorById = (id: string) => motorData.find(m => m.id === id);
export const getCarroById = (id: string) => carroData.find(c => c.id === id);
export const getCategoriaById = (id: string) => categoriaData.find(cat => cat.id === id);
export const getProductoById = (id: string) => productoData.find(p => p.id === id);
export const getItemsByProductoId = (productoId: string) => 
  itemVentaData.filter(item => item.productoId === productoId);

// Obtener marcas únicas
export const getMarcas = () => [...new Set(chasisData.map(ch => ch.marca))];

// Obtener modelos por marca
export const getModelosByMarca = (marca: string) => 
  [...new Set(chasisData.filter(ch => ch.marca === marca).map(ch => ch.modelo))];

// Obtener series por marca y modelo
export const getSeriesByMarcaModelo = (marca: string, modelo: string) =>
  chasisData.filter(ch => ch.marca === marca && ch.modelo === modelo);

// Obtener carros por filtro de vehículo
export const getCarrosByVehiculo = (marca?: string, modelo?: string, serie?: string) => {
  let filtered = [...carroData];
  
  if (marca || modelo || serie) {
    filtered = carroData.filter(carro => {
      const chasis = getChasisById(carro.chasisId);
      if (!chasis) return false;
      
      if (marca && chasis.marca !== marca) return false;
      if (modelo && chasis.modelo !== modelo) return false;
      if (serie && chasis.serie !== serie) return false;
      
      return true;
    });
  }
  
  return filtered;
};

// Buscar productos por código o descripción
export const searchProductos = (query: string) => {
  const q = query.toLowerCase().trim();
  if (!q) return productoData;
  
  return productoData.filter(p => 
    p.codigo.toLowerCase().includes(q) || 
    p.descripcion.toLowerCase().includes(q)
  );
};

// Filtrar productos por categoría
export const getProductosByCategoria = (categoriaId?: string) => {
  if (!categoriaId) return productoData;
  return productoData.filter(p => p.categoriaId === categoriaId);
};

// Filtrar productos compatibles con un carro
export const getProductosByCarro = (carroIds: string[]) => {
  if (carroIds.length === 0) return productoData;
  return productoData.filter(p => 
    p.aplicaciones.some(app => carroIds.includes(app))
  );
};
