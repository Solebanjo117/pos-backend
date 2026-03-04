// Estructura de datos profesional para sistema de autorepuestos

export interface Chasis {
  id: string;
  marca: string;
  modelo: string;
  serie: string;
  año: string;
  tipoCarroceria: string;
  vins: string[]; // VIN numbers associated with this chassis
}

export interface Motor {
  id: string;
  codigo: string;
  cilindraje: string;
  combustible: string;
  transmision: string;
}

export interface Carro {
  id: string;
  chasisId: string;
  motorId: string;
  nombre: string; // Nombre completo para mostrar
}

export interface Categoria {
  id: string;
  nombre: string;
  atributos: string[]; // Ej: ["largo", "alto", "ancho"]
}

export interface Producto {
  id: string;
  codigo: string;
  categoriaId: string;
  aplicaciones: string[]; // IDs de carros compatibles
  atributos: Record<string, string>; // Ej: { largo: "100mm", alto: "50mm" }
  imagen?: string;
  descripcion: string;
}

export interface ItemVenta {
  id: string;
  productoId: string;
  marca: string;
  costo: number;
  precioVenta: number;
  stock: number;
  proveedor: string;
}
