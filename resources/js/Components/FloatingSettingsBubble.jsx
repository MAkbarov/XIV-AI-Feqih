import React, { useState, useRef, useEffect } from 'react';
import { motion, AnimatePresence } from 'framer-motion';
import Icon from '@/Components/Icon';
import UserBackgroundModal from '@/Components/UserBackgroundModal';

const FloatingSettingsBubble = ({ isAuthenticated = false }) => {
    const [isOpen, setIsOpen] = useState(false);
    const [isDragging, setIsDragging] = useState(false);
    const [position, setPosition] = useState({ x: 0, y: 0 });
    const bubbleRef = useRef(null);
    const dragStartTime = useRef(null);
    const BUBBLE_SIZE = 64; // 16 * 4 = 64px (w-16 h-16)
    
    // Initialize position to middle right of screen
    useEffect(() => {
        const updatePosition = () => {
            const windowWidth = window.innerWidth;
            const windowHeight = window.innerHeight;
            const padding = 20; // Minimum distance from edges
            
            setPosition({
                x: Math.min(windowWidth - BUBBLE_SIZE - padding, windowWidth - 100), // Keep away from right edge
                y: Math.min(windowHeight / 2 - BUBBLE_SIZE / 2, windowHeight - BUBBLE_SIZE - padding) // Center vertically but within bounds
            });
        };
        
        updatePosition();
        window.addEventListener('resize', updatePosition);
        return () => window.removeEventListener('resize', updatePosition);
    }, []);

    const constrainPosition = (x, y) => {
        const windowWidth = window.innerWidth;
        const windowHeight = window.innerHeight;
        const padding = 10;
        
        return {
            x: Math.max(padding, Math.min(x, windowWidth - BUBBLE_SIZE - padding)),
            y: Math.max(padding, Math.min(y, windowHeight - BUBBLE_SIZE - padding))
        };
    };

    const handleDragStart = () => {
        dragStartTime.current = Date.now();
        setIsDragging(true);
    };

    const handleDrag = (event, info) => {
        const constrainedPos = constrainPosition(info.point.x - BUBBLE_SIZE / 2, info.point.y - BUBBLE_SIZE / 2);
        setPosition(constrainedPos);
    };

    const handleDragEnd = () => {
        const dragDuration = Date.now() - dragStartTime.current;
        // Only consider it a drag if it lasted more than 150ms
        setTimeout(() => {
            setIsDragging(dragDuration > 150);
        }, 50);
    };

    const handleClick = () => {
        if (!isDragging) {
            setIsOpen(true);
        }
    };

    return (
        <>
            {/* Bubble */}
            <motion.div
                ref={bubbleRef}
                drag
                dragMomentum={false}
                dragTransition={{ bounceStiffness: 600, bounceDamping: 20 }}
                dragElastic={0.1}
                onDragStart={handleDragStart}
                onDrag={handleDrag}
                onDragEnd={handleDragEnd}
                onClick={handleClick}
                className="fixed z-50 cursor-pointer select-none"
                style={{
                    left: position.x,
                    top: position.y,
                }}
                initial={{ scale: 0, opacity: 0 }}
                animate={{ scale: 1, opacity: 1 }}
                transition={{ type: "spring", stiffness: 260, damping: 20 }}
                whileHover={{ scale: 1.1 }}
                whileTap={{ scale: 0.9 }}
                whileDrag={{ scale: 1.05, cursor: "grabbing" }}
            >
                <div className="w-16 h-16 bg-gradient-to-r from-purple-500 to-indigo-600 rounded-full shadow-lg hover:shadow-xl transition-all duration-300 flex items-center justify-center backdrop-blur border border-white/20">
                    <Icon name="settings" size={24} color="#ffffff" />
                </div>
                
                {/* Pulse animation */}
                <div className="absolute inset-0 rounded-full bg-gradient-to-r from-purple-500 to-indigo-600 animate-ping opacity-20"></div>
            </motion.div>

            {/* Background Settings Modal */}
            <UserBackgroundModal 
                isOpen={isOpen} 
                onClose={() => setIsOpen(false)}
                isAuthenticated={isAuthenticated}
            />
        </>
    );
};

export default FloatingSettingsBubble;