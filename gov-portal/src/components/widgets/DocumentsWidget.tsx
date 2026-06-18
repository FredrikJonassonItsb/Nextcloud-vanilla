import { useQuery } from '@tanstack/react-query';
import {
  FileText,
  Folder,
  Image,
  FileSpreadsheet,
  FileVideo,
  File,
  ExternalLink,
  Upload,
  RefreshCw,
  AlertCircle,
  Star,
  Share2,
  Clock,
} from 'lucide-react';
import { getRecentFiles, openFileInNextcloud, getFilePreviewUrl } from '../../api/documents';
import { useAuthStore } from '../../stores/authStore';
import { formatDistanceToNow } from 'date-fns';
import { sv } from 'date-fns/locale';
import type { RecentFile } from '../../types';

export default function DocumentsWidget() {
  const { user } = useAuthStore();

  const {
    data: files,
    isLoading,
    error,
    refetch,
  } = useQuery({
    queryKey: ['recentFiles', user?.id],
    queryFn: () => getRecentFiles(user?.id || '', 8),
    enabled: !!user?.id,
    refetchInterval: 60000, // Refetch every minute
  });

  // Get file type icon
  const getFileIcon = (file: RecentFile) => {
    const mimeType = file.mimeType.toLowerCase();

    if (file.type === 'directory') {
      return <Folder className="w-5 h-5 text-yellow-500" />;
    }
    if (mimeType.startsWith('image/')) {
      return <Image className="w-5 h-5 text-green-500" />;
    }
    if (mimeType.includes('pdf')) {
      return <FileText className="w-5 h-5 text-red-500" />;
    }
    if (mimeType.includes('spreadsheet') || mimeType.includes('excel')) {
      return <FileSpreadsheet className="w-5 h-5 text-green-600" />;
    }
    if (mimeType.includes('video')) {
      return <FileVideo className="w-5 h-5 text-purple-500" />;
    }
    if (
      mimeType.includes('document') ||
      mimeType.includes('word') ||
      mimeType.includes('text')
    ) {
      return <FileText className="w-5 h-5 text-gov-blue-500" />;
    }
    return <File className="w-5 h-5 text-gov-gray-500" />;
  };

  // Format file size
  const formatFileSize = (bytes: number): string => {
    if (bytes === 0) return '';
    const k = 1024;
    const sizes = ['B', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i];
  };

  // Get activity badge
  const getActivityBadge = (file: RecentFile) => {
    switch (file.activity?.type) {
      case 'created':
        return (
          <span className="badge badge-success text-[10px]">Ny</span>
        );
      case 'shared':
        return (
          <span className="badge badge-primary text-[10px]">Delad</span>
        );
      default:
        return null;
    }
  };

  return (
    <section className="widget-card" aria-labelledby="documents-title">
      {/* Header */}
      <div className="widget-header">
        <div className="w-10 h-10 rounded-lg bg-orange-50 flex items-center justify-center">
          <Folder className="w-5 h-5 text-orange-500" />
        </div>
        <div className="flex-1 min-w-0">
          <h2 id="documents-title" className="widget-title">
            Dokument
          </h2>
          <p className="text-xs text-gov-gray-500">
            Senast använda filer
          </p>
        </div>
        <button
          onClick={() => refetch()}
          className="p-2 rounded-md hover:bg-gov-gray-100 transition-colors"
          aria-label="Uppdatera dokument"
          title="Uppdatera"
        >
          <RefreshCw className={`w-4 h-4 text-gov-gray-400 ${isLoading ? 'animate-spin' : ''}`} />
        </button>
      </div>

      {/* Content */}
      <div className="widget-body max-h-[320px] overflow-y-auto custom-scrollbar">
        {isLoading && !files ? (
          <LoadingState />
        ) : error ? (
          <ErrorState onRetry={() => refetch()} />
        ) : !files || files.length === 0 ? (
          <EmptyState />
        ) : (
          <ul className="space-y-1" role="list">
            {files.map((file) => (
              <li key={file.id}>
                <button
                  onClick={() => openFileInNextcloud(file.id)}
                  className="list-item group w-full text-left"
                  aria-label={`${file.name}, ${
                    file.activity?.type === 'modified' ? 'ändrad' : file.activity?.type || ''
                  } ${formatDistanceToNow(new Date(file.modified), {
                    addSuffix: true,
                    locale: sv,
                  })}`}
                >
                  {/* File preview / icon */}
                  <div className="relative flex-shrink-0">
                    {file.mimeType.startsWith('image/') && file.id ? (
                      <div className="w-10 h-10 rounded-lg overflow-hidden bg-gov-gray-100">
                        <img
                          src={getFilePreviewUrl(file.id, 64, 64)}
                          alt=""
                          className="w-full h-full object-cover"
                          onError={(e) => {
                            e.currentTarget.style.display = 'none';
                          }}
                        />
                      </div>
                    ) : (
                      <div className="w-10 h-10 rounded-lg bg-gov-gray-100 flex items-center justify-center">
                        {getFileIcon(file)}
                      </div>
                    )}

                    {/* Favorite indicator */}
                    {file.favorite && (
                      <span className="absolute -top-1 -right-1 w-4 h-4 bg-yellow-400 rounded-full flex items-center justify-center">
                        <Star className="w-2.5 h-2.5 text-white fill-current" />
                      </span>
                    )}
                  </div>

                  {/* File info */}
                  <div className="flex-1 min-w-0">
                    <div className="flex items-center gap-2">
                      <span className="text-sm text-gov-gray-800 truncate font-medium">
                        {file.name}
                      </span>
                      {getActivityBadge(file)}
                    </div>
                    <div className="flex items-center gap-2 text-xs text-gov-gray-500">
                      <span className="flex items-center gap-1">
                        <Clock className="w-3 h-3" />
                        {formatDistanceToNow(new Date(file.modified), {
                          addSuffix: true,
                          locale: sv,
                        })}
                      </span>
                      {file.size > 0 && (
                        <>
                          <span className="text-gov-gray-300">•</span>
                          <span>{formatFileSize(file.size)}</span>
                        </>
                      )}
                      {file.isShared && (
                        <>
                          <span className="text-gov-gray-300">•</span>
                          <Share2 className="w-3 h-3" />
                        </>
                      )}
                    </div>
                  </div>

                  {/* Arrow indicator */}
                  <ExternalLink className="w-4 h-4 text-gov-gray-300 opacity-0 group-hover:opacity-100 transition-opacity flex-shrink-0" />
                </button>
              </li>
            ))}
          </ul>
        )}
      </div>

      {/* Footer */}
      <div className="widget-footer flex items-center justify-between gap-3">
        <a href="/apps/files" className="btn btn-ghost text-sm">
          <ExternalLink className="w-4 h-4" />
          Alla filer
        </a>
        <a href="/apps/files?upload=true" className="btn btn-primary text-sm">
          <Upload className="w-4 h-4" />
          Ladda upp
        </a>
      </div>
    </section>
  );
}

function LoadingState() {
  return (
    <div className="space-y-3">
      {[1, 2, 3, 4].map((i) => (
        <div key={i} className="flex items-center gap-3 animate-pulse">
          <div className="w-10 h-10 rounded-lg bg-gov-gray-200" />
          <div className="flex-1 space-y-2">
            <div className="h-4 bg-gov-gray-200 rounded w-2/3" />
            <div className="h-3 bg-gov-gray-100 rounded w-1/3" />
          </div>
        </div>
      ))}
    </div>
  );
}

function EmptyState() {
  return (
    <div className="empty-state py-6">
      <Folder className="empty-state-icon" />
      <p className="empty-state-text">Inga nyligen använda dokument</p>
      <p className="text-xs text-gov-gray-400 mt-1">
        Dina senaste filer visas här
      </p>
      <a href="/apps/files" className="btn btn-primary mt-4 text-sm">
        <Folder className="w-4 h-4" />
        Öppna filer
      </a>
    </div>
  );
}

function ErrorState({ onRetry }: { onRetry: () => void }) {
  return (
    <div className="error-state py-6">
      <AlertCircle className="error-state-icon" />
      <p className="error-state-text">Kunde inte ladda dokument</p>
      <button onClick={onRetry} className="btn btn-secondary mt-3 text-sm">
        Försök igen
      </button>
    </div>
  );
}
