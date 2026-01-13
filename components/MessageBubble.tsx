
import React from 'react';
import { Message, Source } from '../types';

interface MessageBubbleProps {
  message: Message;
}

const MessageBubble: React.FC<MessageBubbleProps> = ({ message }) => {
  const isUser = message.role === 'user';

  return (
    <div className={`flex w-full mb-6 ${isUser ? 'justify-end' : 'justify-start'}`}>
      <div className={`max-w-[85%] md:max-w-[70%] rounded-2xl p-4 shadow-sm ${
        isUser 
          ? 'bg-blue-600 text-white rounded-tr-none' 
          : 'bg-white text-slate-800 border border-slate-100 rounded-tl-none'
      }`}>
        <div className="text-sm font-semibold mb-1 opacity-70 uppercase tracking-wider">
          {isUser ? 'You' : 'Pearly'}
        </div>
        <div className="whitespace-pre-wrap leading-relaxed text-[15px]">
          {message.content}
        </div>
        
        {!isUser && message.sources && message.sources.length > 0 && (
          <div className="mt-4 pt-3 border-t border-slate-100">
            <div className="text-xs font-bold text-slate-400 mb-2">VERIFIED SOURCES:</div>
            <div className="flex flex-wrap gap-2">
              {message.sources.map((source, idx) => (
                <a
                  key={idx}
                  href={source.url}
                  target="_blank"
                  rel="noopener noreferrer"
                  className="text-xs bg-slate-50 hover:bg-blue-50 text-blue-600 px-2 py-1 rounded border border-slate-200 transition-colors"
                >
                  {source.title}
                </a>
              ))}
            </div>
          </div>
        )}
        
        <div className={`text-[10px] mt-2 opacity-50 ${isUser ? 'text-right' : 'text-left'}`}>
          {new Date(message.timestamp).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}
        </div>
      </div>
    </div>
  );
};

export default MessageBubble;
