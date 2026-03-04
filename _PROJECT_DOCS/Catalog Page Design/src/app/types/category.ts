export interface CategoryAttribute {
  id: string;
  name: string;
  dataType: 'text' | 'number' | 'select';
  options?: string[]; // Para tipo select
  required: boolean;
  order: number;
}

export interface Category {
  id: string;
  name: string;
  attributes: CategoryAttribute[];
}
