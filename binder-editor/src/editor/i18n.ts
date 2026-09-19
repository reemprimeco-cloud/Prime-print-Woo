import type { Issue } from '@binder/shared';
import type { Lang } from './config';

type Dict = Record<string, string>;

/**
 * Editor strings. Keyed by stable codes so the shared validator's English
 * messages (for logs and the admin screen) never reach a customer directly.
 * {placeholders} are filled from the issue's own detail values, which come
 * from spec.json and the file — nothing here states a measurement.
 */
const en: Dict = {
  title_binder_outer: 'Design the outer cover',
  title_binder_inner: 'Design the inner liner',
  subtitle: 'Flat print size {w} × {h} mm',
  close: 'Close',
  upload_title: 'Your artwork',
  upload_cta: 'Upload your design',
  upload_replace: 'Replace image',
  drop_hint: 'Drag an image here, or click to choose one. JPG, PNG or WebP.',
  uploading: 'Uploading… {pct}%',
  upload_type: 'Please choose a JPG, PNG or WebP image.',
  upload_size: 'That file is too large.',
  upload_failed: 'The upload did not work. Please try again.',
  upload_rate: 'Too many uploads. Please wait a little and try again.',
  position: 'Position and size',
  fill: 'Fill',
  fit: 'Fit',
  center: 'Center',
  rotate_left: 'Rotate left',
  rotate_right: 'Rotate right',
  size: 'Size',
  quality: 'Print quality',
  quality_good: 'Sharp',
  quality_ok: 'Acceptable',
  quality_low: 'Too low to print',
  dpi: '{dpi} DPI at this size',
  checks: 'Checks',
  checks_ok: 'Everything looks good.',
  checks_none: 'Upload an image to begin.',
  'issue.dpi.block': 'The image is only {dpi} DPI at this size, too low to print. Use a larger file or make the picture smaller.',
  'issue.dpi.warn': 'The image is {dpi} DPI at this size and may print blurry.',
  'issue.safe.outside': 'Part of the image is close to a cut or fold line and may be trimmed.',
  'issue.bleed.empty': 'The picture does not cover the whole sheet. White may show at the edges after cutting. Use Fill.',
  'issue.turnin.violation': 'This reaches the wrap-around edge, which is folded behind the board.',
  saving: 'Saving…',
  saved: 'Saved',
  save_failed: 'Could not save. Retrying…',
  approve: 'Approve design',
  approving: 'Preparing your print file…',
  approving_hint: 'This can take up to a minute. Please keep this window open.',
  ready_title: 'Your design is ready',
  ready_text: 'It has been approved for printing.',
  proof: 'View proof (PDF)',
  done: 'Done',
  edit_again: 'Change my design',
  try_again: 'Try again',
  err_busy: 'The design service is busy. Please try again in a moment.',
  err_unavailable: 'The design service is not available right now. Please try again later.',
  err_network: 'Connection problem. Please check your internet and try again.',
  err_generic: 'Something went wrong. Please try again.',
  err_rejected: 'This design cannot be printed as it is:',
  err_failed: 'We could not prepare your file:',
  'err.image.unavailable': 'The image could not be read by our printer.',
  'err.image.invalid': 'The file is not a readable image.',
  'err.image.unsupported_type': 'This image type is not supported.',
  'err.image.too_large': 'The image is too large.',
  legend: 'Guide lines',
  legend_bleed: 'Bleed {mm} mm',
  legend_trim: 'Cut line',
  legend_fold: 'Fold line',
  legend_safe: 'Safe area {mm} mm',
  legend_miter: 'Corner cut',
  legend_turnin: 'Wrap-around {mm} mm',
  legend_note: 'The guide lines help you place your design. They are not printed.',
  loading: 'Loading…',
  load_failed: 'The editor could not load.',
  reopened: 'Your saved design has been reopened.',
};

const ar: Dict = {
  title_binder_outer: 'صمّم الغلاف الخارجي',
  title_binder_inner: 'صمّم البطانة الداخلية',
  subtitle: 'مقاس الطباعة المسطّح {w} × {h} مم',
  close: 'إغلاق',
  upload_title: 'تصميمك',
  upload_cta: 'ارفع تصميمك',
  upload_replace: 'استبدال الصورة',
  drop_hint: 'اسحب الصورة إلى هنا أو اضغط للاختيار. بصيغة JPG أو PNG أو WebP.',
  uploading: 'جارٍ الرفع… {pct}%',
  upload_type: 'الرجاء اختيار صورة بصيغة JPG أو PNG أو WebP.',
  upload_size: 'حجم الملف كبير جدًا.',
  upload_failed: 'لم ينجح الرفع. الرجاء المحاولة مرة أخرى.',
  upload_rate: 'عدد كبير من عمليات الرفع. الرجاء الانتظار قليلًا ثم المحاولة.',
  position: 'الموضع والحجم',
  fill: 'تعبئة',
  fit: 'احتواء',
  center: 'توسيط',
  rotate_left: 'تدوير لليسار',
  rotate_right: 'تدوير لليمين',
  size: 'الحجم',
  quality: 'جودة الطباعة',
  quality_good: 'واضحة',
  quality_ok: 'مقبولة',
  quality_low: 'منخفضة جدًا للطباعة',
  dpi: '{dpi} نقطة في البوصة بهذا الحجم',
  checks: 'الفحوصات',
  checks_ok: 'كل شيء سليم.',
  checks_none: 'ارفع صورة للبدء.',
  'issue.dpi.block': 'دقة الصورة {dpi} نقطة في البوصة فقط بهذا الحجم، وهي منخفضة جدًا للطباعة. استخدم ملفًا أكبر أو صغّر الصورة.',
  'issue.dpi.warn': 'دقة الصورة {dpi} نقطة في البوصة بهذا الحجم وقد تظهر غير واضحة عند الطباعة.',
  'issue.safe.outside': 'جزء من الصورة قريب من خط القص أو الطي وقد يُقصّ.',
  'issue.bleed.empty': 'الصورة لا تغطي كامل الورقة، وقد يظهر بياض عند الحواف بعد القص. استخدم «تعبئة».',
  'issue.turnin.violation': 'هذا الجزء يصل إلى حافة اللف التي تُطوى خلف الكرتون.',
  saving: 'جارٍ الحفظ…',
  saved: 'تم الحفظ',
  save_failed: 'تعذّر الحفظ. جارٍ إعادة المحاولة…',
  approve: 'اعتماد التصميم',
  approving: 'جارٍ تجهيز ملف الطباعة…',
  approving_hint: 'قد يستغرق ذلك حتى دقيقة. الرجاء إبقاء هذه النافذة مفتوحة.',
  ready_title: 'تصميمك جاهز',
  ready_text: 'تم اعتماده للطباعة.',
  proof: 'عرض النسخة التجريبية (PDF)',
  done: 'تم',
  edit_again: 'تعديل التصميم',
  try_again: 'إعادة المحاولة',
  err_busy: 'خدمة التصميم مشغولة. الرجاء المحاولة بعد قليل.',
  err_unavailable: 'خدمة التصميم غير متاحة حاليًا. الرجاء المحاولة لاحقًا.',
  err_network: 'مشكلة في الاتصال. الرجاء التحقق من الإنترنت والمحاولة مرة أخرى.',
  err_generic: 'حدث خطأ. الرجاء المحاولة مرة أخرى.',
  err_rejected: 'لا يمكن طباعة هذا التصميم كما هو:',
  err_failed: 'تعذّر تجهيز ملفك:',
  'err.image.unavailable': 'تعذّرت قراءة الصورة من قبل خادم الطباعة.',
  'err.image.invalid': 'الملف ليس صورة صالحة.',
  'err.image.unsupported_type': 'نوع الصورة هذا غير مدعوم.',
  'err.image.too_large': 'الصورة كبيرة جدًا.',
  legend: 'خطوط الإرشاد',
  legend_bleed: 'الزيادة {mm} مم',
  legend_trim: 'خط القص',
  legend_fold: 'خط الطي',
  legend_safe: 'المنطقة الآمنة {mm} مم',
  legend_miter: 'قص الزاوية',
  legend_turnin: 'حافة اللف {mm} مم',
  legend_note: 'خطوط الإرشاد تساعدك على وضع تصميمك ولا تُطبع.',
  loading: 'جارٍ التحميل…',
  load_failed: 'تعذّر تحميل المحرر.',
  reopened: 'تم فتح تصميمك المحفوظ.',
};

const dicts: Record<Lang, Dict> = { en, ar };

export type T = (key: string, vars?: Record<string, string | number>) => string;

export function makeT(lang: Lang): T {
  return (key, vars = {}) => {
    const raw = dicts[lang][key] ?? dicts.en[key] ?? key;
    return raw.replace(/\{(\w+)\}/g, (_, k: string) => String(vars[k] ?? ''));
  };
}

/** A customer-facing sentence for a validation or render issue. */
export function issueText(t: T, issue: Issue): string {
  const detail = (issue.detail ?? {}) as Record<string, string | number>;
  const key = `issue.${issue.code}`;
  const fallback = `err.${issue.code}`;
  const known = key in en ? key : fallback in en ? fallback : '';
  return known ? t(known, detail) : t('err_generic');
}
