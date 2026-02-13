/**
 * Форматирует номер телефона в формат +7 (XXX) XXX-XX-XX
 * Принимает строку с цифрами в любом формате
 */
export function formatPhoneNumber(value: string): string {
  // Оставляем только цифры
  const digits = value.replace(/\D/g, '');

  // Если пусто — пусто
  if (!digits) return '';

  // Если начинается с 8 — заменяем на 7
  let normalized = digits;
  if (normalized.startsWith('8') && normalized.length > 1) {
    normalized = '7' + normalized.slice(1);
  }

  // Если не начинается с 7, добавляем 7
  if (!normalized.startsWith('7') && normalized.length > 0) {
    normalized = '7' + normalized;
  }

  // Ограничиваем длину до 11 цифр
  normalized = normalized.slice(0, 11);

  // Форматируем по маске +7 (XXX) XXX-XX-XX
  const countryCode = normalized.slice(0, 1); // 7
  const areaCode = normalized.slice(1, 4);
  const firstPart = normalized.slice(4, 7);
  const secondPart = normalized.slice(7, 9);
  const thirdPart = normalized.slice(9, 11);

  let result = `+${countryCode}`;
  if (areaCode) result += ` (${areaCode}`;
  if (areaCode.length === 3) result += ')';
  if (firstPart) result += ` ${firstPart}`;
  if (secondPart) result += `-${secondPart}`;
  if (thirdPart) result += `-${thirdPart}`;

  return result;
}

/**
 * Очищает форматированный номер до формата +7XXXXXXXXXX
 * Для отправки на сервер
 */
export function cleanPhoneNumber(formatted: string): string {
  const digits = formatted.replace(/\D/g, '');
  if (!digits) return '';
  return `+${digits}`;
}
