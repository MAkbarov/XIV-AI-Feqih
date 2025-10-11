import { Head } from '@inertiajs/react';
import UserLayout from '@/Layouts/UserLayout';
import { motion } from 'framer-motion';
import { useTheme } from '@/Components/ThemeProvider';
import Icon from '@/Components/Icon';

export default function Contact({ contact = {}, auth, settings = {}, footerSettings = {}, theme = {} }) {
  const { isDarkMode } = useTheme();
  
  const title = contact.title || 'Əlaqə';
  const content = contact.content || '';
  const email = contact.email || '';
  const primaryColor = theme?.primary_color || '#10b981';
  const secondaryColor = theme?.secondary_color || '#06b6d4';
  const accentColor = theme?.accent_color || '#f59e0b';

  const containerVariants = {
    hidden: { opacity: 0, y: 20 },
    visible: {
      opacity: 1,
      y: 0,
      transition: {
        duration: 0.6,
        staggerChildren: 0.1
      }
    }
  };

  const itemVariants = {
    hidden: { opacity: 0, y: 20 },
    visible: {
      opacity: 1,
      y: 0,
      transition: { duration: 0.5 }
    }
  };

  return (
    <UserLayout auth={auth} settings={settings} footerSettings={footerSettings}>
      <Head title={title} />
      <section className="w-full px-4 sm:px-6 lg:px-8 py-8 sm:py-12 lg:py-16 relative">
        {/* Decorative background elements */}
        <div className="absolute inset-0 overflow-hidden pointer-events-none">
          <div className="absolute top-10 right-10 w-32 h-32 rounded-full opacity-10" 
               style={{ background: `linear-gradient(135deg, ${primaryColor}, ${secondaryColor})` }}></div>
          <div className="absolute bottom-20 left-10 w-24 h-24 rounded-full opacity-10" 
               style={{ background: `linear-gradient(135deg, ${accentColor}, ${primaryColor})` }}></div>
        </div>

        <motion.div 
          className="max-w-6xl mx-auto relative z-10"
          variants={containerVariants}
          initial="hidden"
          animate="visible"
        >
          {/* Header section */}
          <motion.div 
            variants={itemVariants}
            className="text-center mb-12"
          >
            <div className="inline-flex items-center justify-center w-20 h-20 rounded-full mb-6"
                 style={{ background: `linear-gradient(135deg, ${primaryColor}, ${secondaryColor})` }}>
              <Icon name="mail" size={32} color="white" />
            </div>
            <h1 className="text-4xl sm:text-5xl lg:text-6xl font-bold text-gray-900 dark:text-gray-100 mb-4">
              {title}
            </h1>
            <p className="text-lg text-gray-600 dark:text-gray-400 max-w-2xl mx-auto">
              Bizimlə əlaqə saxlayın və suallarınızı verən cavabları alın
            </p>
          </motion.div>

          <div className="grid grid-cols-1 lg:grid-cols-3 gap-8">
            {/* Main content */}
            <motion.div 
              variants={itemVariants}
              className="lg:col-span-2"
            >
              <div className="bg-white/90 dark:bg-gray-800/90 backdrop-blur-lg rounded-3xl border border-gray-200 dark:border-gray-700 shadow-2xl p-8 sm:p-10 hover:shadow-3xl transition-all duration-500">
                {content ? (
                  <div className="prose prose-lg dark:prose-invert max-w-none text-gray-800 dark:text-gray-100">
                    <div dangerouslySetInnerHTML={{ __html: content }} />
                  </div>
                ) : (
                  <div className="space-y-6">
                    <h2 className="text-2xl font-bold text-gray-900 dark:text-gray-100 mb-6">
                      Bizimlə Əlaqə Saxlayın
                    </h2>
                    <p className="text-gray-600 dark:text-gray-300 leading-relaxed">
                      Hər hansı bir sualınız, təklifiniz və ya köməkə ehtiyacınız varsa, bizimlə əlaqə saxlamaqdan çəkinməyin. 
                      Komandamız sizə kömək etmək üçün hər zaman hazırdır.
                    </p>
                    <div className="bg-gray-50 dark:bg-gray-700/50 rounded-2xl p-6">
                      <h3 className="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4">
                        📞 Əlaqə Məlumatları
                      </h3>
                      <ul className="space-y-3 text-gray-600 dark:text-gray-300">
                        <li className="flex items-center gap-3">
                          <Icon name="clock" size={18} color={primaryColor} />
                          <span>İş saatları: Bazar ertəsi - Cümə, 09:00 - 18:00</span>
                        </li>
                        <li className="flex items-center gap-3">
                          <Icon name="location_on" size={18} color={primaryColor} />
                          <span>Ünvan: Bakı, Azərbaycan</span>
                        </li>
                      </ul>
                    </div>
                  </div>
                )}
              </div>
            </motion.div>

            {/* Contact info sidebar */}
            <motion.div 
              variants={itemVariants}
              className="space-y-6"
            >
              {/* Email contact */}
              {email && (
                <motion.div 
                  whileHover={{ scale: 1.02 }}
                  className="bg-white/90 dark:bg-gray-800/90 backdrop-blur-lg rounded-2xl border border-gray-200 dark:border-gray-700 shadow-xl p-6 hover:shadow-2xl transition-all duration-300"
                >
                  <div className="text-center">
                    <div className="w-12 h-12 rounded-full mx-auto mb-4 flex items-center justify-center"
                         style={{ background: `linear-gradient(135deg, ${primaryColor}, ${secondaryColor})` }}>
                      <Icon name="mail" size={20} color="white" />
                    </div>
                    <h3 className="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-2">
                      E-poçt
                    </h3>
                    <a 
                      href={`mailto:${email}`} 
                      className="inline-flex items-center gap-2 px-6 py-3 rounded-xl font-medium text-white transition-all duration-300 hover:shadow-lg hover:-translate-y-0.5"
                      style={{ background: `linear-gradient(135deg, ${primaryColor}, ${secondaryColor})` }}
                    >
                      <Icon name="send" size={16} color="white" />
                      {email}
                    </a>
                  </div>
                </motion.div>
              )}

              {/* Quick links */}
              <motion.div 
                whileHover={{ scale: 1.02 }}
                className="bg-white/90 dark:bg-gray-800/90 backdrop-blur-lg rounded-2xl border border-gray-200 dark:border-gray-700 shadow-xl p-6 hover:shadow-2xl transition-all duration-300"
              >
                <h3 className="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4 flex items-center gap-2">
                  <Icon name="info" size={20} color={primaryColor} />
                  Faydalı Keçidlər
                </h3>
                <div className="space-y-3">
                  <a 
                    href="/terms" 
                    className="flex items-center gap-3 text-gray-600 dark:text-gray-300 hover:text-gray-900 dark:hover:text-gray-100 transition-colors p-2 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700"
                  >
                    <Icon name="description" size={16} color={primaryColor} />
                    İstifadə Şərtləri
                  </a>
                  <a 
                    href="/privacy" 
                    className="flex items-center gap-3 text-gray-600 dark:text-gray-300 hover:text-gray-900 dark:hover:text-gray-100 transition-colors p-2 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700"
                  >
                    <Icon name="privacy_tip" size={16} color={primaryColor} />
                    Məxfilik Siyasəti
                  </a>
                  <a 
                    href="/" 
                    className="flex items-center gap-3 text-gray-600 dark:text-gray-300 hover:text-gray-900 dark:hover:text-gray-100 transition-colors p-2 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700"
                  >
                    <Icon name="chat" size={16} color={primaryColor} />
                    Çatbota Qayıt
                  </a>
                </div>
              </motion.div>

              {/* Support hours */}
              <motion.div 
                whileHover={{ scale: 1.02 }}
                className="bg-gradient-to-br from-blue-50 to-indigo-50 dark:from-blue-900/20 dark:to-indigo-900/20 backdrop-blur-lg rounded-2xl border border-blue-200 dark:border-blue-800 shadow-xl p-6 hover:shadow-2xl transition-all duration-300"
              >
                <div className="text-center">
                  <Icon name="schedule" size={32} color={primaryColor} className="mx-auto mb-3" />
                  <h3 className="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-2">
                    Dəstək Saatları
                  </h3>
                  <p className="text-sm text-gray-600 dark:text-gray-300">
                    Hər gün 24/7 çatbot dəstəyi mövcuddur
                  </p>
                </div>
              </motion.div>
            </motion.div>
          </div>
        </motion.div>
      </section>
    </UserLayout>
  );
}
