import { useState } from 'react';
import { CatalogHeader } from '../components/catalog-header';
import { VehicleSelector } from '../components/vehicle-selector';
import { ProductFilters } from '../components/product-filters';
import { ProductGrid } from '../components/product-grid';
import { ProductDetail } from '../components/product-detail';
import { Product, Vehicle, FilterState } from '../types/catalog';
import { mockProducts } from '../data/mock-products';

export function CatalogPage() {
  const [searchQuery, setSearchQuery] = useState('');
  const [selectedVehicle, setSelectedVehicle] = useState<Vehicle | null>(null);
  const [showVehicleSelector, setShowVehicleSelector] = useState(false);
  const [selectedCategory, setSelectedCategory] = useState<string | null>(null);
  const [viewMode, setViewMode] = useState<'grid' | 'list'>('grid');
  const [selectedProduct, setSelectedProduct] = useState<Product | null>(null);
  const [filters, setFilters] = useState<FilterState>({
    categories: [],
    priceRange: [0, 10000],
    availability: 'all',
    brands: []
  });

  const handleSearch = (query: string) => {
    setSearchQuery(query);
  };

  const handleVehicleSelect = (vehicle: Vehicle) => {
    setSelectedVehicle(vehicle);
    setShowVehicleSelector(false);
  };

  const handleCategorySelect = (category: string) => {
    setSelectedCategory(category);
  };

  const handleProductSelect = (product: Product) => {
    setSelectedProduct(product);
  };

  const handleCloseDetail = () => {
    setSelectedProduct(null);
  };

  // Filtrar productos
  const filteredProducts = mockProducts.filter(product => {
    // Filtro de búsqueda
    if (searchQuery) {
      const query = searchQuery.toLowerCase();
      if (
        !product.code.toLowerCase().includes(query) &&
        !product.name.toLowerCase().includes(query) &&
        !product.model.toLowerCase().includes(query)
      ) {
        return false;
      }
    }

    // Filtro de vehículo
    if (selectedVehicle) {
      const isCompatible = product.compatibility.some(
        v => v.brand === selectedVehicle.brand && v.model === selectedVehicle.model
      );
      if (!isCompatible) return false;
    }

    // Filtro de categoría rápida
    if (selectedCategory && product.category !== selectedCategory) {
      return false;
    }

    // Filtros sidebar
    if (filters.categories.length > 0 && !filters.categories.includes(product.category)) {
      return false;
    }

    const minPrice = Math.min(...product.brands.map(b => b.price));
    if (minPrice < filters.priceRange[0] || minPrice > filters.priceRange[1]) {
      return false;
    }

    if (filters.availability === 'in-stock') {
      const hasStock = product.brands.some(b => b.stock > 0);
      if (!hasStock) return false;
    }

    if (filters.brands.length > 0) {
      const hasBrand = product.brands.some(b => filters.brands.includes(b.brand));
      if (!hasBrand) return false;
    }

    return true;
  });

  return (
    <div>
      {/* Header */}
      <CatalogHeader
        searchQuery={searchQuery}
        onSearch={handleSearch}
        onOpenVehicleSelector={() => setShowVehicleSelector(true)}
        selectedVehicle={selectedVehicle}
        onCategorySelect={handleCategorySelect}
        selectedCategory={selectedCategory}
      />

      {/* Vehicle Selector Modal */}
      {showVehicleSelector && (
        <VehicleSelector
          onSelect={handleVehicleSelect}
          onClose={() => setShowVehicleSelector(false)}
        />
      )}

      {/* Main Content */}
      <div className="container mx-auto px-4 py-6">
        <div className="flex gap-6">
          {/* Sidebar Filters */}
          <aside className="w-64 flex-shrink-0">
            <ProductFilters
              filters={filters}
              onFiltersChange={setFilters}
            />
          </aside>

          {/* Products Grid */}
          <main className="flex-1">
            <ProductGrid
              products={filteredProducts}
              viewMode={viewMode}
              onViewModeChange={setViewMode}
              onProductSelect={handleProductSelect}
            />
          </main>
        </div>
      </div>

      {/* Product Detail Modal */}
      {selectedProduct && (
        <ProductDetail
          product={selectedProduct}
          onClose={handleCloseDetail}
        />
      )}
    </div>
  );
}
