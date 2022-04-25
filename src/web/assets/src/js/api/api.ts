export const configureApi = (url) => ({
    baseURL: url,
    headers: {
        'X-Requested-With': 'XMLHttpRequest'
    }
})

export const executeApi = async(api, url, variables, callback) => {
    try {
        const response = await api.post(url, variables)
        if ( callback && response.data ) {
            callback(response.data)
        }
    } catch (error) {
        console.error('xhr', error)
    }
}
