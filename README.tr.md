# HikaShop için Payment - Paymos

[English](README.md) | [简体中文](README.zh-CN.md) | [Русский](README.ru.md) | [Español](README.es.md) | [Deutsch](README.de.md) | **Türkçe**

HikaShop'ta Paymos barındırılan ödeme sayfası üzerinden stabil coin ödemeleri kabul edin. Eklenti ücretsizdir ve bir Paymos hesabı ile projesi gerektirir. Paymos hizmet ücretleri ve blokzincir ağ ücretleri uygulanabilir. [Paymos fiyatlandırması](https://paymos.io/tr/pricing)

[![İzleyin: 12 Resmi CMS Kripto Eklentisi — Tek Tıkla Bağlantı (Paymos)](https://i.ytimg.com/vi/VMLUGVumYkg/maxresdefault.jpg)](https://www.youtube.com/watch?v=VMLUGVumYkg)
> **Video anlatım (1:12, 6 dilde altyazı):** [YouTube'da izleyin](https://www.youtube.com/watch?v=VMLUGVumYkg)

## Gereksinimler

- Joomla 5.4 veya 6.2 ile HikaShop 6.8.1 veya 6.8 serisindeki daha yeni bir yama sürümü.
- cURL ve OpenSSL etkinleştirilmiş PHP 8.3 veya üzeri.
- MySQL veya MariaDB. Yerel HikaShop sipariş, geçmiş, ürün, sipariş ürünü, indirim ve kullanıcı tabloları InnoDB kullanmalıdır.
- Herkese açık bir HTTPS mağaza vitrini ve standart HikaShop sipariş durumları: `created`, `confirmed` ve `refunded`. **Order created status** ayarını `created` olarak belirleyin ve `confirmed` ile `refunded` durumlarını kullanılabilir tutun.

## Kurulum ve bağlantı

1. Sürümlenmiş kurulum ZIP paketini indirin ve Joomla'da **System → Install → Extensions** üzerinden yükleyin. [Resmi sürümler](https://github.com/Paymos-labs/hikashop/releases)
2. **System → Manage → Plugins** ekranında **Payment - Paymos for HikaShop** eklentisini etkinleştirin. HikaShop'ta **System → Payment methods** bölümünü açın, yeni bir Paymos yöntemi oluşturup kaydedin.
3. **Connect Paymos** düğmesini seçin. Onay sayfasını açın, HikaShop'ta gösterilen kodu eşleştirin ve bir Paymos projesi seçin. Onaydan sonra HikaShop'a dönün.
4. **Sandbox** seçeneğini belirleyin, modu kaydedin ve bir siparişle test edin. Hazır olduğunuzda yöntemi müşterilere açmadan önce **Live** seçeneğini belirleyip kaydedin.

ZIP paketi Paymos PHP SDK'yı içinde barındırır. Joomla eklentiyi, veritabanı tablolarını ve güncelleme sitesini kurar. Bağlantı işlemi kaydedilen ödeme yöntemini ve mağaza adresini onaylanan projeye bağlar. Kanonik HTTPS adresinizi kullanın; adres değişirse yeniden bağlanın.

Paymos projenizin **Success URL** ayarını Joomla temel URL'niz altındaki yerel HikaShop ödeme dönüş sayfasına yönlendirebilirsiniz. `https://store.example/shop/` adresindeki bir site için `https://store.example/shop/index.php?option=com_hikashop&ctrl=checkout&task=after_end` adresini kullanın. Birden fazla mağaza aynı projeyi paylaşıyorsa bu proje genelindeki ayarı gözden geçirin.

## Ödeme ve teslimat

Ödeme adımı Paymos'a projeyi, sipariş referansını ve kayıtlı siparişin tutarı ile para birimini gönderir. Sipariş `#__hikashop_order_product` tablosunda 1 ile 50 arasında geçerli ürün satırı içerdiğinde (`#__hikashop_product` tablosundaki isteğe bağlı düz metin açıklamalarla birlikte), ödeme adımı HikaShop sipariş toplamı (`order_full_price`) yanında normalleştirilmiş `products[]` kalemlerini de gönderir. Eksik veya boş açıklamalar ürün bazında atlanır. Ürün görselleri sorgulanmaz ve gönderilmez. Siparişte 0 ürün satırı, 50'den fazla ürün satırı veya herhangi bir geçersiz ürün satırı (kesirli miktar, boş başlık ya da negatif/geçersiz birim fiyat gibi) varsa, ödeme akışı yalnızca tutar içeren (`amount-only`) faturaya geri döner. Normalleştirilmiş anlık görüntü ilk istekten önce `#__hikashop_paymos_attempt.products_json` alanına kaydedilir; böylece yeniden denemeler kayıtlı ürün bilgilerini tekrar kullanır. `image_url` içeren eski anlık görüntülerin saklanan JSON verisi değişmeden kalır; yalnızca bu kullanımdan kaldırılmış anahtar giden kopyadan çıkarılır. Müşteri ödemeyi Paymos üzerinde tamamlar ve HikaShop sipariş sayfasına dönebilir. Ödeme onayı, imzalı bir bildirim ve eşleşen bir Paymos API sonucundan gelir. Dönüş sayfası HikaShop sipariş durumunu gösterir.

HikaShop, tam ödenmiş ve eşleşen bir siparişi bir kez onaylar. Doğrulanmış fazla ödeme içeren bir ödeme de siparişi onaylayabilir. Kısmi ödemeler kalan tutarı bekler veya onaylanmamış hâlde kalır; geç ya da uyuşmayan ödemeler inceleme gerektirir. Stok güncellemeleri ve ürün erişimi, onaylanmış bir sipariş için yapılandırılan HikaShop iş akışını izler.

Bir güncelleme eksikse HikaShop'ta siparişi açın ve Paymos panelinde **Check payment** seçeneğine tıklayın. Bu işlem kayıtlı faturayı çekip mutabakat yapar.

## İadeler

Sipariş düzenleme yetkisine sahip bir yönetici olarak HikaShop'ta ödenmiş bir siparişi açın. İade desteği bulunan bir proje için Paymos paneli uygun **Refund full payment** ve **Refund overpayment** işlemlerini gösterir. Paymos tutarı hesaplar ve ödeme sırasında kaydedilen doğrulanmış gönderici adresini kullanır.

- Tam iade, Paymos tamamlanmayı doğruladıktan sonra siparişi `refunded` durumuna alır. HikaShop yapılandırılmış sipariş ve stok davranışını uygular.
- Tamamlanan bir fazla ödeme iadesi mevcut sipariş durumunu korur ve geçmişe bir kayıt ekler.
- Bekleyen veya belirsiz bir sonuç için mevcut işlemde **Check refund** seçeneğini kullanın. Paymos durumu bildirdikçe imzalı bildirim veya mutabakat işlemi günceller.
- Bu eklenti dışında başlatılan ve kayıtlı bir iade işlemiyle eşleştirilemeyen bir iade, Paymos ve HikaShop üzerinde incelenmelidir.

## Kimlik bilgileri ve kaldırma

Kimlik bilgileri Joomla kurulum gizli anahtarıyla şifrelenerek saklanır. Entegrasyonu kaldırmadan önce bekleyen ödeme ve iadeleri sonuçlandırın. **Disconnect** düğmesi ilgili yöntem için kayıtlı kimlik bilgilerini siler ve yeniden bağlanana kadar ödeme güncellemelerini durdurur. Eklentiyi kaldırmak eklentinin kendi tablolarını ve kimlik bilgilerini siler. Yerel HikaShop siparişleri ve geçmişi korunur.

## Lisans ve destek

Bu eklenti GPL-2.0-or-later lisansını kullanır. Gömülü Paymos PHP SDK ise MIT Lisansı altındadır. [Eklenti lisansı](LICENSE), [üçüncü taraf bildirimleri](NOTICE).

Entegrasyon rehberi ve destek için [Paymos dokümantasyonuna](https://paymos.io/tr/docs/cms-hikashop) bakın veya [Paymos destek ekibiyle](mailto:support@paymos.io) iletişime geçin.
