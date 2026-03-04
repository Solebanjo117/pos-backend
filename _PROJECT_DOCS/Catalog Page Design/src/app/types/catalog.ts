export interface Vehicle {
  brand: string;
  model: string;
  series: string;
  year: number;
}

export interface ProductBrand {
  brand: string;
  price: number;
  stock: number;
}

export interface Product {
  id: string;
  code: string;
  name: string;
  model: string;
  category: string;
  image: string;
  compatibility: Vehicle[];
  brands: ProductBrand[];
  measures?: string;
  description?: string;
}

export interface FilterState {
  categories: string[];
  priceRange: [number, number];
  availability: 'all' | 'in-stock' | 'out-of-stock';
  brands: string[];
}
