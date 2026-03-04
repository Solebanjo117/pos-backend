export const formatCurrency = (amount: number) => {
  return new Intl.NumberFormat('es-PA', {
    style: 'currency',
    currency: 'USD',
  }).format(amount);
};
