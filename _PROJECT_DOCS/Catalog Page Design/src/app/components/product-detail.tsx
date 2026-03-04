import { useState } from 'react';
import { X, Package, Car, Ruler, ShoppingCart, Plus, Minus } from 'lucide-react';
import { Button } from './ui/button';
import { Badge } from './ui/badge';
import { Card } from './ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from './ui/table';
import { Product } from '../types/catalog';

interface ProductDetailProps {
  product: Product;
  onClose: () => void;
}

export function ProductDetail({ product, onClose }: ProductDetailProps) {
  const [quantities, setQuantities] = useState<Record<string, number>>(
    Object.fromEntries(product.brands.map(b => [b.brand, 1]))
  );

  const handleQuantityChange = (brand: string, delta: number) => {
    setQuantities(prev => ({
      ...prev,
      [brand]: Math.max(1, (prev[brand] || 1) + delta)
    }));
  };

  const handleAddToCart = (brand: string, price: number) => {
    const quantity = quantities[brand] || 1;
    alert(`Agregado al carrito:\n${product.name}\nMarca: ${brand}\nCantidad: ${quantity}\nTotal: $${(price * quantity).toLocaleString()}`);
  };

  return (
    <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4 overflow-y-auto">
      <Card className="bg-white rounded-lg shadow-xl max-w-4xl w-full my-8">
        {/* Header */}
        <div className="flex items-center justify-between p-6 border-b sticky top-0 bg-white rounded-t-lg z-10">
          <h2 className="text-xl font-semibold">Detalle del Producto</h2>
          <Button variant="ghost" size="sm" onClick={onClose}>
            <X className="w-5 h-5" />
          </Button>
        </div>

        {/* Content */}
        <div className="p-6">
          <div className="grid md:grid-cols-2 gap-6 mb-6">
            {/* Imagen */}
            <div>
              <img
                src={product.image}
                alt={product.name}
                className="w-full h-96 object-cover rounded-lg"
              />
            </div>

            {/* Información Principal */}
            <div className="space-y-4">
              <div>
                <h3 className="text-2xl font-semibold mb-2">{product.name}</h3>
                <p className="text-lg text-gray-600">{product.model}</p>
              </div>

              <div className="flex flex-wrap gap-2">
                <Badge variant="outline" className="text-sm">
                  <Package className="w-4 h-4 mr-1" />
                  Código: {product.code}
                </Badge>
                <Badge variant="secondary" className="text-sm">
                  {product.category}
                </Badge>
              </div>

              {product.description && (
                <div>
                  <h4 className="font-medium mb-1">Descripción</h4>
                  <p className="text-gray-600">{product.description}</p>
                </div>
              )}

              {product.measures && (
                <div>
                  <h4 className="font-medium mb-1 flex items-center gap-1">
                    <Ruler className="w-4 h-4" />
                    Medidas
                  </h4>
                  <p className="text-gray-600">{product.measures}</p>
                </div>
              )}
            </div>
          </div>

          {/* Compatibilidad */}
          <div className="mb-6">
            <h4 className="font-medium mb-3 flex items-center gap-2">
              <Car className="w-4 h-4" />
              Compatibilidad de Vehículos
            </h4>
            <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-2">
              {product.compatibility.map((vehicle, index) => (
                <Card key={index} className="p-3 bg-gray-50">
                  <p className="font-medium">{vehicle.brand} {vehicle.model}</p>
                  <p className="text-sm text-gray-600">
                    {vehicle.series} - {vehicle.year}
                  </p>
                </Card>
              ))}
            </div>
          </div>

          {/* Tabla de Marcas, Precios y Stock */}
          <div>
            <h4 className="font-medium mb-3">Opciones Disponibles</h4>
            <div className="border rounded-lg overflow-hidden">
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>Marca</TableHead>
                    <TableHead>Precio Unitario</TableHead>
                    <TableHead>Stock</TableHead>
                    <TableHead>Cantidad</TableHead>
                    <TableHead>Total</TableHead>
                    <TableHead></TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {product.brands.map((brandData) => {
                    const quantity = quantities[brandData.brand] || 1;
                    const total = brandData.price * quantity;
                    const hasStock = brandData.stock > 0;

                    return (
                      <TableRow key={brandData.brand}>
                        <TableCell className="font-medium">
                          {brandData.brand}
                        </TableCell>
                        <TableCell>
                          ${brandData.price.toLocaleString()}
                        </TableCell>
                        <TableCell>
                          {hasStock ? (
                            <Badge variant="default" className="bg-green-500">
                              {brandData.stock} unidades
                            </Badge>
                          ) : (
                            <Badge variant="secondary">Sin stock</Badge>
                          )}
                        </TableCell>
                        <TableCell>
                          <div className="flex items-center gap-2">
                            <Button
                              size="sm"
                              variant="outline"
                              onClick={() => handleQuantityChange(brandData.brand, -1)}
                              disabled={!hasStock}
                            >
                              <Minus className="w-3 h-3" />
                            </Button>
                            <span className="w-8 text-center">{quantity}</span>
                            <Button
                              size="sm"
                              variant="outline"
                              onClick={() => handleQuantityChange(brandData.brand, 1)}
                              disabled={!hasStock || quantity >= brandData.stock}
                            >
                              <Plus className="w-3 h-3" />
                            </Button>
                          </div>
                        </TableCell>
                        <TableCell className="font-semibold text-blue-600">
                          ${total.toLocaleString()}
                        </TableCell>
                        <TableCell>
                          <Button
                            size="sm"
                            onClick={() => handleAddToCart(brandData.brand, brandData.price)}
                            disabled={!hasStock}
                          >
                            <ShoppingCart className="w-4 h-4 mr-1" />
                            Agregar
                          </Button>
                        </TableCell>
                      </TableRow>
                    );
                  })}
                </TableBody>
              </Table>
            </div>
          </div>
        </div>

        {/* Footer */}
        <div className="flex justify-end gap-2 p-6 border-t bg-gray-50 rounded-b-lg">
          <Button variant="outline" onClick={onClose}>
            Cerrar
          </Button>
        </div>
      </Card>
    </div>
  );
}
