<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Providence Technologies - CCTV, Solar & Electrical Services</title>

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600,700" rel="stylesheet" />
    <link href='https://fonts.googleapis.com/css?family=Poppins' rel='stylesheet'>

    <!-- Font Awesome for icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <!-- Styles -->
    @vite(['resources/css/app.css', 'resources/js/app.js'])

</head>

<body class="bg-white text-gray-800 font-['Poppins']">
    <!-- Header -->
    <header class="bg-white shadow-sm sticky top-0 z-50">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between items-center py-4">
                <!-- Logo -->
                <div class="flex items-center">
                    <h1 class="text-2xl font-bold text-black">Providence Technologies</h1>
                </div>

                <!-- Social Media & CTA -->
                <div class="flex items-center space-x-4">
                    <div class="flex space-x-3">
                        <a href="#" class="text-gray-600 hover:text-orange-400 transition-colors">
                            <i class="fab fa-facebook-f"></i>
                        </a>
                        <a href="#" class="text-gray-600 hover:text-orange-400 transition-colors">
                            <i class="fab fa-twitter"></i>
                        </a>
                        <a href="#" class="text-gray-600 hover:text-orange-400 transition-colors">
                            <i class="fab fa-instagram"></i>
                        </a>
                        <a href="#" class="text-gray-600 hover:text-orange-400 transition-colors">
                            <i class="fab fa-linkedin-in"></i>
                        </a>
                    </div>
                    <button
                        class="bg-orange text-orange-500 hover:text-white px-6 py-2 rounded-md hover:bg-orange-500 border-2 border-orange-500 transition duration-500 ease-in-out">
                        Get a Quote
                    </button>
                </div>
            </div>

            <!-- Navigation -->
            <nav class="border-t border-gray-200 py-4">
                <ul class="flex space-x-8 justify-center">
                    <li><a href="#hero" class="text-gray-600 hover:text-orange-400 transition-colors">Home</a></li>
                    <li><a href="#features" class="text-gray-600 hover:text-orange-400 transition-colors">Services</a>
                    </li>
                    <li><a href="#contact" class="text-gray-600 hover:text-orange-400 transition-colors">Contact</a>
                    </li>
                    <li><a href="#testimonials"
                            class="text-gray-600 hover:text-orange-400 transition-colors">Testimonials</a></li>
                </ul>
            </nav>
        </div>
    </header>

    <!-- Hero Section -->
    <div
        style="background-image: url('https://media.istockphoto.com/id/2148804020/photo/male-electrician-working-in-a-switchboard-with-fuses.webp?a=1&b=1&s=612x612&w=0&k=20&c=2t-aQaIsygWBui2YWJVjoCac1rUiaJOHys0sM6_ZaHs='); background-size: cover; background-position: center; background-repeat: no-repeat;">
        <section id="hero" class="bg-gradient-to-l from-black to-gray-900/80 text-white py-20">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-12 items-center">
                    <div>
                        <h1 class="text-5xl font-bold mb-6">Powering Your Future with Technology</h1>
                        <p class="text-xl text-gray-300 mb-8">Professional CCTV, solar systems, and electrical services.
                            Reliable, efficient, and sustainable solutions for your home and business.</p>
                        <button
                            class="bg-orange text-orange-500 hover:text-white border-2 border-orange-500 px-8 py-4 rounded-md text-lg font-semibold hover:bg-orange-500 transition-colors">
                            Get a Quote
                        </button>
                    </div>
                    <div class="relative">
                        <div class="bg-gray-800 rounded-lg overflow-hidden h-[60dvh] flex items-center justify-center">
                            <img src="https://media.istockphoto.com/id/2161430645/photo/solar-panels-with-sky-reflection-isolated-on-white-background.webp?a=1&b=1&s=612x612&w=0&k=20&c=8trjV6FW9DLlFl7P_OZF1jU7a1FA41DYnjbE-b3YShM="
                                alt="Hero Image" class="w-full h-full object-cover">
                        </div>
                    </div>
                </div>
            </div>
        </section>

    </div>

    <!-- Features Section -->
    <section id="features" class="py-20 bg-white">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
                <div class="text-center">
                    <div class="bg-orange w-16 h-16 rounded-full flex items-center justify-center mx-auto mb-4">
                        <i class="fas fa-video text-white text-2xl"></i>
                    </div>
                    <h3 class="text-xl font-semibold mb-2">CCTV Installation</h3>
                    <p class="text-gray-600">Professional CCTV camera installation and monitoring systems for security.
                    </p>
                </div>
                <div class="text-center">
                    <div class="bg-orange w-16 h-16 rounded-full flex items-center justify-center mx-auto mb-4">
                        <i class="fas fa-solar-panel text-white text-2xl"></i>
                    </div>
                    <h3 class="text-xl font-semibold mb-2">Solar Systems</h3>
                    <p class="text-gray-600">Complete solar power solutions for homes and businesses with expert
                        installation.</p>
                </div>
                <div class="text-center">
                    <div class="bg-orange w-16 h-16 rounded-full flex items-center justify-center mx-auto mb-4">
                        <i class="fas fa-bolt text-white text-2xl"></i>
                    </div>
                    <h3 class="text-xl font-semibold mb-2">Electrical Services</h3>
                    <p class="text-gray-600">Domestic and industrial electrical installation, maintenance, and repairs.
                    </p>
                </div>
            </div>
        </div>
    </section>

    <!-- About Section -->
    <section class="py-20 bg-white">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-12 items-center">
                <div class="relative">
                    <div class="bg-gray-200 rounded-lg overflow-hidden h-96 flex items-center justify-center">
                        <img src="https://media.istockphoto.com/id/1436112666/photo/man-an-electrical-technician-working-in-a-switchboard-with-fuses-uses-a-tablet.webp?a=1&b=1&s=612x612&w=0&k=20&c=AD9-KXlK63liO3xMgHucjJO0u1jbfdfVh13EhtD89ck="
                            class="w-full h-full object-cover" alt="">
                    </div>
                </div>
                <div>
                    <h2 class="text-4xl font-bold mb-6">Powering Your Security & Energy Needs</h2>
                    <p class="text-gray-600 mb-6">Our team of certified technicians provides comprehensive electrical,
                        solar, and security solutions. We use high-quality equipment and follow industry standards to
                        ensure reliable and efficient installations.</p>
                    <ul class="space-y-3 mb-8">
                        <li class="flex items-center">
                            <i class="fas fa-check text-orange-400 mr-3"></i>
                            <span>Certified Technicians</span>
                        </li>
                        <li class="flex items-center">
                            <i class="fas fa-check text-orange-400 mr-3"></i>
                            <span>Quality Equipment</span>
                        </li>
                        <li class="flex items-center">
                            <i class="fas fa-check text-orange-400 mr-3"></i>
                            <span>24/7 Support</span>
                        </li>
                        <li class="flex items-center">
                            <i class="fas fa-check text-orange-400 mr-3"></i>
                            <span>Competitive Pricing</span>
                        </li>
                    </ul>
                    <button
                        class="bg-orange text-orange-500 hover:text-white border-2 border-orange-500 px-6 py-3 rounded-md hover:bg-orange-500 transition duration-500 ease-in-out">
                        Read More
                    </button>
                </div>
            </div>
        </div>
    </section>
    <!-- About Section 2 -->
    <section class="py-20 bg-black text-white">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-12 items-center">
                <div>
                    <div class="mb-8">
                        <div class="flex items-center mb-4">
                            <i class="fas fa-shield-alt text-orange-400 text-3xl mr-3"></i>
                            <span class="text-4xl font-semibold">Trusted Security Experts</span>
                        </div>
                        <p class="text-gray-300">
                            With years of experience, our team delivers reliable security and energy solutions tailored
                            to your needs. We are committed to your safety and satisfaction, using only the best
                            practices and equipment in the industry.
                        </p>
                    </div>
                    <ul class="space-y-3 mb-8">
                        <li class="flex items-center">
                            <i class="fas fa-check text-orange-400 mr-3"></i>
                            <span>High-definition video surveillance</span>
                        </li>
                        <li class="flex items-center">
                            <i class="fas fa-check text-orange-400 mr-3"></i>
                            <span>Remote monitoring and playback</span>
                        </li>
                        <li class="flex items-center">
                            <i class="fas fa-check text-orange-400 mr-3"></i>
                            <span>Motion detection and alerts</span>
                        </li>
                        <li class="flex items-center">
                            <i class="fas fa-check text-orange-400 mr-3"></i>
                            <span>Professional installation & support</span>
                        </li>
                    </ul>
                    <button
                        class="bg-orange text-orange-500 hover:text-white border-2 border-orange-500 px-6 py-3 rounded-md hover:bg-orange-500 transition duration-500 ease-in-out">
                        Read More
                    </button>
                </div>
                <div class="relative">
                    <div class="bg-gray-200 rounded-lg overflow-hidden h-96 flex items-center justify-center">
                        <img src="https://plus.unsplash.com/premium_photo-1675016457613-2291390d1bf6?w=600&auto=format&fit=crop&q=60&ixlib=rb-4.1.0&ixid=M3wxMjA3fDB8MHxzZWFyY2h8MXx8Y2N0diUyMGNhbWVyYXxlbnwwfHwwfHx8MA%3D%3D"
                            class="w-full h-full object-cover" alt="">
                    </div>
                </div>
            </div>
        </div>
    </section>
    <!-- About Section 3 -->
    <section class="py-20 bg-white">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-12 items-center">
                <div class="relative">
                    <div
                        class="bg-gray-200 rounded-lg overflow-hidden h-96 flex items-center justify-center border-2 border-orange-500">
                        <img src="https://media.istockphoto.com/id/2178932781/photo/street-lamp-from-solar-energy-that-generates-electricity-from-solar-panels-soft-and-selective.jpg?s=612x612&w=0&k=20&c=yNCM-wooVeKXuVIz8E92FnKGXfcqWl8j29cwlK43K9M="
                            class="w-full h-full object-cover" alt="">
                    </div>
                </div>
                <div>
                    <div class="mb-8">
                        <div class="flex items-center mb-4">
                            <i class="fas fa-sun text-orange-400 text-3xl mr-3"></i>
                            <span class="text-4xl font-bold text-black ">Solar Street Lighting Solutions</span>
                        </div>
                        <p class="text-gray-600">
                            Illuminate your streets and outdoor spaces with our advanced solar street lighting systems.
                            Our solutions are designed for efficiency, durability, and sustainability, ensuring bright
                            and reliable lighting all year round while reducing energy costs.
                        </p>
                    </div>
                    <ul class="space-y-3 mb-8">
                        <li class="flex items-center">
                            <i class="fas fa-check text-orange-400 mr-3"></i>
                            <span>High-efficiency solar panels for maximum energy conversion</span>
                        </li>
                        <li class="flex items-center">
                            <i class="fas fa-check text-orange-400 mr-3"></i>
                            <span>Automatic dusk-to-dawn operation for convenience</span>
                        </li>
                        <li class="flex items-center">
                            <i class="fas fa-check text-orange-400 mr-3"></i>
                            <span>Weather-resistant and durable construction</span>
                        </li>
                        <li class="flex items-center">
                            <i class="fas fa-check text-orange-400 mr-3"></i>
                            <span>Low maintenance and long-lasting LED lighting</span>
                        </li>
                    </ul>

                    <button
                        class="bg-orange text-orange-500 hover:text-white border-2 border-orange-500 px-6 py-3 rounded-md hover:bg-orange-500 transition duration-500 ease-in-out">
                        Read More
                    </button>
                </div>

            </div>
        </div>
    </section>

    <!-- Services Section -->
    <section class="py-20 bg-black text-white">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <h2 class="text-4xl font-bold text-center mb-16">Comprehensive Technology Solutions</h2>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-8">
                <div class="bg-gray-800 rounded-lg p-6 text-center">
                    <div class="bg-gray-700 w-20 h-20 rounded-lg mx-auto mb-4 flex items-center justify-center">
                        <i class="fas fa-video text-3xl text-orange-400"></i>
                    </div>
                    <h3 class="text-xl font-semibold mb-3">CCTV Camera Systems</h3>
                    <p class="text-gray-400 mb-4">Professional surveillance and security camera installation.</p>
                    <button class="bg-orange text-white px-4 py-2 rounded-md hover:bg-orange-500 transition-colors">
                        Read More
                    </button>
                </div>
                <div class="bg-gray-800 rounded-lg p-6 text-center">
                    <div class="bg-gray-700 w-20 h-20 rounded-lg mx-auto mb-4 flex items-center justify-center">
                        <i class="fas fa-solar-panel text-3xl text-orange-400"></i>
                    </div>
                    <h3 class="text-xl font-semibold mb-3">Solar Power Systems</h3>
                    <p class="text-gray-400 mb-4">Complete solar energy solutions for homes and businesses.</p>
                    <button class="bg-orange text-white px-4 py-2 rounded-md hover:bg-orange-500 transition-colors">
                        Read More
                    </button>
                </div>
                <div class="bg-gray-800 rounded-lg p-6 text-center">
                    <div class="bg-gray-700 w-20 h-20 rounded-lg mx-auto mb-4 flex items-center justify-center">
                        <i class="fas fa-bolt text-3xl text-orange-400"></i>
                    </div>
                    <h3 class="text-xl font-semibold mb-3">Electrical Installation</h3>
                    <p class="text-gray-400 mb-4">Domestic and industrial electrical services and maintenance.</p>
                    <button class="bg-orange text-white px-4 py-2 rounded-md hover:bg-orange-500 transition-colors">
                        Read More
                    </button>
                </div>
                <div class="bg-gray-800 rounded-lg p-6 text-center">
                    <div class="bg-gray-700 w-20 h-20 rounded-lg mx-auto mb-4 flex items-center justify-center">
                        <i class="fas fa-lightbulb text-3xl text-orange-400"></i>
                    </div>
                    <h3 class="text-xl font-semibold mb-3">Solar Street Lights</h3>
                    <p class="text-gray-400 mb-4">Energy-efficient solar street lighting solutions.</p>
                    <button class="bg-orange text-white px-4 py-2 rounded-md hover:bg-orange-500 transition-colors">
                        Read More
                    </button>
                </div>
            </div>
        </div>
    </section>

    <!-- Technicians Section -->
    <section class="py-20 bg-white">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between items-center mb-5">
                <h2 class="text-4xl font-bold">The Technicians That You Can Trust!</h2>
                <button
                    class="bg-orange text-orange-500 hover:text-white border-2 border-orange-500 px-6 py-3 rounded-md hover:bg-orange-500 transition duration-500 ease-in-out">
                    View All
                </button>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6 gap-8">
                <div class="text-center">
                    <div class="bg-orange w-16 h-16 rounded-full flex items-center justify-center mx-auto mb-4">
                        <i class="fas fa-clock text-white text-xl"></i>
                    </div>
                    <h3 class="font-semibold mb-2">24/7 Service</h3>
                    <p class="text-gray-600 text-sm">Round the clock support</p>
                </div>
                <div class="text-center">
                    <div class="bg-orange w-16 h-16 rounded-full flex items-center justify-center mx-auto mb-4">
                        <i class="fas fa-dollar-sign text-white text-xl"></i>
                    </div>
                    <h3 class="font-semibold mb-2">Flexible Price</h3>
                    <p class="text-gray-600 text-sm">Competitive rates</p>
                </div>
                <div class="text-center">
                    <div class="bg-orange w-16 h-16 rounded-full flex items-center justify-center mx-auto mb-4">
                        <i class="fas fa-users text-white text-xl"></i>
                    </div>
                    <h3 class="font-semibold mb-2">Great Technicians</h3>
                    <p class="text-gray-600 text-sm">Expert team</p>
                </div>
                <div class="text-center">
                    <div class="bg-orange w-16 h-16 rounded-full flex items-center justify-center mx-auto mb-4">
                        <i class="fas fa-shield-alt text-white text-xl"></i>
                    </div>
                    <h3 class="font-semibold mb-2">Warranty</h3>
                    <p class="text-gray-600 text-sm">Guaranteed work</p>
                </div>
                <div class="text-center">
                    <div class="bg-orange w-16 h-16 rounded-full flex items-center justify-center mx-auto mb-4">
                        <i class="fas fa-tools text-white text-xl"></i>
                    </div>
                    <h3 class="font-semibold mb-2">Quality Tools</h3>
                    <p class="text-gray-600 text-sm">Professional equipment</p>
                </div>
                <div class="text-center">
                    <div class="bg-orange w-16 h-16 rounded-full flex items-center justify-center mx-auto mb-4">
                        <i class="fas fa-star text-white text-xl"></i>
                    </div>
                    <h3 class="font-semibold mb-2">Best Service</h3>
                    <p class="text-gray-600 text-sm">Top quality</p>
                </div>
            </div>
        </div>
    </section>

    <!-- Statistics Section -->
    <section class="py-20 bg-white">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-8 text-center">
                <div>
                    <div class="text-5xl font-bold text-orange-400 mb-2">24</div>
                    <div class="text-gray-600">Years of Experience</div>
                </div>
                <div>
                    <div class="text-5xl font-bold text-orange-400 mb-2">785</div>
                    <div class="text-gray-600">Gadgets Repaired</div>
                </div>
                <div>
                    <div class="text-5xl font-bold text-orange-400 mb-2">145</div>
                    <div class="text-gray-600">Happy Customers</div>
                </div>
                <div>
                    <div class="text-5xl font-bold text-orange-400 mb-2">99</div>
                    <div class="text-gray-600">Awards Won</div>
                </div>
            </div>
        </div>
    </section>

    <!-- Pricing Section -->
    <section class="py-20 bg-gray-100">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <h2 class="text-4xl font-bold text-center mb-16">Affordable Price For You</h2>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
                <div class="border border-gray-200 rounded-lg p-6 bg-white">
                    <div class="bg-gray-100 w-20 h-20 rounded-lg mx-auto mb-4 flex items-center justify-center">
                        <i class="fas fa-video text-3xl text-orange-400"></i>
                    </div>
                    <h3 class="text-xl font-semibold text-center mb-4">CCTV Installation</h3>
                    <div class="space-y-3">
                        <div class="flex justify-between">
                            <span>4-Channel System</span>
                            <span class="text-orange-400 font-semibold">Affordable</span>
                        </div>
                        <div class="flex justify-between">
                            <span>8-Channel System</span>
                            <span class="text-orange-400 font-semibold">Affordable</span>
                        </div>
                        <div class="flex justify-between">
                            <span>16-Channel System</span>
                            <span class="text-orange-400 font-semibold">Affordable</span>
                        </div>
                    </div>
                </div>
                <div class="border border-gray-200 rounded-lg p-6 bg-white">
                    <div class="bg-gray-100 w-20 h-20 rounded-lg mx-auto mb-4 flex items-center justify-center">
                        <i class="fas fa-solar-panel text-3xl text-orange-400"></i>
                    </div>
                    <h3 class="text-xl font-semibold text-center mb-4">Solar Power Systems</h3>
                    <div class="space-y-3">
                        <div class="flex justify-between">
                            <span>1KVA System</span>
                            <span class="text-orange-400 font-semibold">Affordable</span>
                        </div>
                        <div class="flex justify-between">
                            <span>2KVA System</span>
                            <span class="text-orange-400 font-semibold">Affordable</span>
                        </div>
                        <div class="flex justify-between">
                            <span>5KVA System</span>
                            <span class="text-orange-400 font-semibold">Affordable</span>
                        </div>
                    </div>
                </div>
                <div class="border border-gray-200 rounded-lg p-6 bg-white">
                    <div class="bg-gray-100 w-20 h-20 rounded-lg mx-auto mb-4 flex items-center justify-center">
                        <i class="fas fa-bolt text-3xl text-orange-400"></i>
                    </div>
                    <h3 class="text-xl font-semibold text-center mb-4">Electrical Services</h3>
                    <div class="space-y-3">
                        <div class="flex justify-between">
                            <span>House Wiring</span>
                            <span class="text-orange-400 font-semibold">Affordable</span>
                        </div>
                        <div class="flex justify-between">
                            <span>Industrial Setup</span>
                            <span class="text-orange-400 font-semibold">Affordable</span>
                        </div>
                        <div class="flex justify-between">
                            <span>Maintenance</span>
                            <span class="text-orange-400 font-semibold">Affordable</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Contact Section -->
    <section id="contact" class="py-20 bg-white">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-12">
                <div>
                    <h2 class="text-4xl font-bold mb-6">Professional Installation Process</h2>
                    <p class="text-gray-600 mb-8">Our systematic approach ensures every installation is done correctly
                        and efficiently. We follow industry best practices to deliver reliable and long-lasting
                        solutions.</p>
                    <div class="space-y-4">
                        <div class="flex items-center">
                            <i class="fas fa-phone text-orange-400 mr-3"></i>
                            <span>08035799046, 08122965500, 08093066480</span>
                        </div>
                        <div class="flex items-center">
                            <i class="fas fa-envelope text-orange-400 mr-3"></i>
                            <span>providenceelectengconsultant@gmail.com</span>
                        </div>
                        <div class="flex items-center">
                            <i class="fas fa-map-marker-alt text-orange-400 mr-3"></i>
                            <span>No. 8 Illokoye street odume obosu, Anambra State, Nigeria</span>
                        </div>
                    </div>
                    {{-- <div class="mt-8 bg-gray-100 rounded-lg p-6">
                        <div class="text-center">
                            <i class="fas fa-play-circle text-4xl text-orange-400 mb-4"></i>
                            <p class="text-gray-600">Watch our installation process</p>
                        </div>
                    </div> --}}
                </div>
                <div>
                    <form class="space-y-6">
                        <div>
                            <input type="text" placeholder="Your Name"
                                class="w-full px-4 py-3 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-orange">
                        </div>
                        <div>
                            <input type="email" placeholder="Email"
                                class="w-full px-4 py-3 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-orange">
                        </div>
                        <div>
                            <input type="tel" placeholder="Phone"
                                class="w-full px-4 py-3 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-orange">
                        </div>
                        <div>
                            <input type="text" placeholder="Subject"
                                class="w-full px-4 py-3 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-orange">
                        </div>
                        <div>
                            <textarea placeholder="Message" rows="4"
                                class="w-full px-4 py-3 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-orange"></textarea>
                        </div>
                        <button type="submit"
                            class="w-full bg-orange border-2 border-orange-500 text-orange-500 hover:text-white py-3 rounded-md hover:bg-orange-500 transition duration-500 ease-in-out">
                            Send Message
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </section>

    {{-- <!-- FAQ Section -->
    <section class="py-20 bg-white">
        <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">
            <h2 class="text-4xl font-bold text-center mb-16">Frequently Asked Questions</h2>
            <div class="space-y-4">
                <div class="border border-gray-200 rounded-lg p-6">
                    <h3 class="text-lg font-semibold mb-2">What services do you provide?</h3>
                    <p class="text-gray-600">We provide CCTV camera installation, solar power systems, domestic &
                        industrial electrical installation/maintenance, and solar street lighting solutions.</p>
                </div>
                <div class="border border-gray-200 rounded-lg p-6">
                    <h3 class="text-lg font-semibold mb-2">How long does installation take?</h3>
                    <p class="text-gray-600">Installation time varies by project size. CCTV systems typically take 1-2
                        days, solar systems 3-5 days, and electrical work depends on complexity.</p>
                </div>
                <div class="border border-gray-200 rounded-lg p-6">
                    <h3 class="text-lg font-semibold mb-2">Do you offer warranty on installations?</h3>
                    <p class="text-gray-600">Yes, we provide comprehensive warranties on all our installations and
                        equipment to ensure your peace of mind.</p>
                </div>
            </div>
        </div>
    </section> --}}

    <!-- Testimonials Section -->
    <section id="testimonials" class="py-20 bg-black text-white">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <h2 class="text-4xl font-bold text-center mb-16">Positive Reviews From Customers</h2>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
                <div class="bg-gray-800 rounded-lg p-6">
                    <div class="flex text-orange-400 mb-4">
                        <i class="fas fa-star"></i>
                        <i class="fas fa-star"></i>
                        <i class="fas fa-star"></i>
                        <i class="fas fa-star"></i>
                        <i class="fas fa-star"></i>
                    </div>
                    <p class="text-gray-300 mb-4">"Excellent CCTV installation! The system works perfectly and provides
                        great security coverage for our business."</p>
                    <div class="flex items-center">
                        <div class="w-12 h-12 bg-gray-600 rounded-full mr-4"></div>
                        <div>
                            <div class="font-semibold">Chief Okonkwo</div>
                            <div class="text-gray-400 text-sm">CCTV Installation</div>
                        </div>
                    </div>
                </div>
                <div class="bg-gray-800 rounded-lg p-6">
                    <div class="flex text-orange-400 mb-4">
                        <i class="fas fa-star"></i>
                        <i class="fas fa-star"></i>
                        <i class="fas fa-star"></i>
                        <i class="fas fa-star"></i>
                        <i class="fas fa-star"></i>
                    </div>
                    <p class="text-gray-300 mb-4">"Professional solar installation team. Our power system has been
                        running smoothly for months now!"</p>
                    <div class="flex items-center">
                        <div class="w-12 h-12 bg-gray-600 rounded-full mr-4"></div>
                        <div>
                            <div class="font-semibold">Mrs. Adebayo</div>
                            <div class="text-gray-400 text-sm">Solar System</div>
                        </div>
                    </div>
                </div>
                <div class="bg-gray-800 rounded-lg p-6">
                    <div class="flex text-orange-400 mb-4">
                        <i class="fas fa-star"></i>
                        <i class="fas fa-star"></i>
                        <i class="fas fa-star"></i>
                        <i class="fas fa-star"></i>
                        <i class="fas fa-star"></i>
                    </div>
                    <p class="text-gray-300 mb-4">"Outstanding electrical work. They completed our industrial wiring
                        project on time and within budget."</p>
                    <div class="flex items-center">
                        <div class="w-12 h-12 bg-gray-600 rounded-full mr-4"></div>
                        <div>
                            <div class="font-semibold">Engr. Mohammed</div>
                            <div class="text-gray-400 text-sm">Electrical Installation</div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="flex justify-center mt-8">
                <div class="flex space-x-2">
                    <div class="w-3 h-3 bg-orange rounded-full"></div>
                    <div class="w-3 h-3 bg-gray-600 rounded-full"></div>
                    <div class="w-3 h-3 bg-gray-600 rounded-full"></div>
                </div>
            </div>
        </div>
    </section>

    <!-- Blog Section -->
    {{-- <section class="py-20 bg-white">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between items-center mb-12">
                <h2 class="text-4xl font-bold">Articles About Gadget</h2>
                <button class="bg-orange text-white px-6 py-3 rounded-md hover:bg-orange-500 transition-colors">
                    View All
                </button>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
                <div class="border border-gray-200 rounded-lg overflow-hidden">
                    <div class="bg-gray-200 h-48"></div>
                    <div class="p-6">
                        <span class="text-orange-400 text-sm font-semibold">Security Tips</span>
                        <h3 class="text-xl font-semibold mt-2 mb-3">Choosing the Right CCTV System for Your Business</h3>
                        <p class="text-gray-600 mb-4">Learn how to select the perfect surveillance system to protect your business and assets.</p>
                        <button class="text-orange-400 font-semibold hover:text-dark-orange transition-colors">
                            Read More
                        </button>
                    </div>
                </div>
                <div class="border border-gray-200 rounded-lg overflow-hidden">
                    <div class="bg-gray-200 h-48"></div>
                    <div class="p-6">
                        <span class="text-orange-400 text-sm font-semibold">Solar Energy</span>
                        <h3 class="text-xl font-semibold mt-2 mb-3">Benefits of Solar Power for Nigerian Homes</h3>
                        <p class="text-gray-600 mb-4">Discover how solar energy can reduce your electricity bills and provide reliable power.</p>
                        <button class="text-orange-400 font-semibold hover:text-dark-orange transition-colors">
                            Read More
                        </button>
                    </div>
                </div>
                <div class="border border-gray-200 rounded-lg overflow-hidden">
                    <div class="bg-gray-200 h-48"></div>
                    <div class="p-6">
                        <span class="text-orange-400 text-sm font-semibold">Electrical Safety</span>
                        <h3 class="text-xl font-semibold mt-2 mb-3">Electrical Safety Standards for Nigerian Buildings</h3>
                        <p class="text-gray-600 mb-4">Essential electrical safety guidelines for homes and commercial buildings in Nigeria.</p>
                        <button class="text-orange-400 font-semibold hover:text-dark-orange transition-colors">
                            Read More
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </section> --}}

    <!-- Client Logos Section -->
    <section class="py-20 bg-white">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-center items-center space-x-12 opacity-50">
                <div class="text-2xl font-bold text-gray-400">DINBOX</div>
                <div class="text-2xl font-bold text-gray-400">NATUSKA</div>
                <div class="text-2xl font-bold text-gray-400">DAVID DENG</div>
                <div class="text-2xl font-bold text-gray-400">BREAZY</div>
                <div class="text-2xl font-bold text-gray-400">INTERVAL</div>
            </div>
        </div>
    </section>

    <!-- CTA Section -->
    <section class="py-20 bg-black text-white relative">
        <div class="absolute inset-0 bg-black opacity-90"></div>
        <div class="relative max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 text-center">
            <h2 class="text-5xl font-bold mb-8">For The Ultimate Electronic Repair Experience</h2>
            <div class="flex justify-center space-x-4">
                <button
                    class="bg-orange text-white px-8 py-4 rounded-md text-lg font-semibold hover:bg-orange-500 transition-colors">
                    Get a Quote
                </button>
                <button
                    class="border border-white text-white px-8 py-4 rounded-md text-lg font-semibold hover:bg-white hover:text-black transition-colors">
                    Contact Us
                </button>
            </div>
        </div>
    </section>

    <!-- Footer -->
    <footer class="bg-black text-white py-16">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="grid grid-cols-1 md:grid-cols-4 gap-8">
                <div>
                    <h3 class="text-xl font-bold mb-4">Providence Technologies</h3>
                    <div class="flex space-x-4 mb-4">
                        <a href="#" class="text-gray-400 hover:text-orange-400 transition-colors">
                            <i class="fab fa-facebook-f"></i>
                        </a>
                        <a href="#" class="text-gray-400 hover:text-orange-400 transition-colors">
                            <i class="fab fa-twitter"></i>
                        </a>
                        <a href="#" class="text-gray-400 hover:text-orange-400 transition-colors">
                            <i class="fab fa-instagram"></i>
                        </a>
                        <a href="#" class="text-gray-400 hover:text-orange-400 transition-colors">
                            <i class="fab fa-linkedin-in"></i>
                        </a>
                    </div>
                    <p class="text-gray-400 text-sm">© 2024 Providence Technologies. All Rights Reserved.</p>
                </div>
                <div>
                    <h4 class="font-semibold mb-4">Contact Us</h4>
                    <div class="space-y-2 text-gray-400">
                        <p>No. 8 Illokoye street odume obosu, Anambra State, Nigeria</p>
                        <p>08035799046, 08122965500, 08093066480</p>
                        <p>providenceelectengconsultant@gmail.com</p>
                    </div>
                </div>
                <div>
                    <h4 class="font-semibold mb-4">Services</h4>
                    <ul class="space-y-2 text-gray-400">
                        <li><a href="#" class="hover:text-orange-400 transition-colors">CCTV Cameras</a></li>
                        <li><a href="#" class="hover:text-orange-400 transition-colors">Solar Systems</a></li>
                        <li><a href="#" class="hover:text-orange-400 transition-colors">Electrical
                                Installation</a></li>
                        <li><a href="#" class="hover:text-orange-400 transition-colors">Solar Street Lights</a>
                        </li>
                    </ul>
                </div>
                <div>
                    <h4 class="font-semibold mb-4">Support</h4>
                    <ul class="space-y-2 text-gray-400">
                        <li><a href="#" class="hover:text-orange-400 transition-colors">FAQ</a></li>
                        <li><a href="#" class="hover:text-orange-400 transition-colors">Privacy Policy</a></li>
                        <li><a href="#" class="hover:text-orange-400 transition-colors">Terms & Conditions</a>
                        </li>
                    </ul>
                </div>
            </div>
            <div class="border-t border-gray-800 mt-8 pt-8 text-center text-gray-400">
                <p>Copyright 2024 Providence Technologies. All Rights Reserved. | Privacy Policy | Terms & Conditions
                </p>
            </div>
        </div>
    </footer>
</body>

</html>
